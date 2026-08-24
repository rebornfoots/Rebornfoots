<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$configured = adminIsConfigured();
$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (!$configured) {
        $loginError = 'Admin login has not been configured on the server.';
    } elseif (!validCsrf($_POST['csrf_token'] ?? null)) {
        $loginError = 'Your session expired. Refresh the page and try again.';
    } else {
        $blockedUntil = (int) ($_SESSION['login_blocked_until'] ?? 0);
        if ($blockedUntil > time()) {
            $loginError = 'Too many attempts. Try again in ' . ($blockedUntil - time()) . ' seconds.';
        } else {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $expectedUsername = (string) appConfig('F2H_ADMIN_USERNAME');
            $passwordHash = (string) appConfig('F2H_ADMIN_PASSWORD_HASH');

            if (hash_equals($expectedUsername, $username) && password_verify($password, $passwordHash)) {
                session_regenerate_id(true);
                $_SESSION['admin_authenticated_at'] = time();
                $_SESSION['admin_last_seen'] = time();
                $_SESSION['admin_username'] = $expectedUsername;
                $_SESSION['login_attempts'] = 0;
                unset($_SESSION['login_blocked_until']);
                header('Location: /admin/');
                exit;
            }

            $attempts = (int) ($_SESSION['login_attempts'] ?? 0) + 1;
            $_SESSION['login_attempts'] = $attempts;
            if ($attempts >= 5) {
                $_SESSION['login_attempts'] = 0;
                $_SESSION['login_blocked_until'] = time() + 300;
                $loginError = 'Too many attempts. Login is paused for 5 minutes.';
            } else {
                usleep(350000);
                $loginError = 'The username or password is incorrect.';
            }
        }
    }
}

if (!adminIsAuthenticated()) {
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Admin Login | InbornFood</title>
  <link rel="stylesheet" href="/admin/styles.css">
</head>
<body class="login-page">
  <main class="login-shell">
    <section class="login-card">
      <a class="admin-brand" href="/" aria-label="InbornFood storefront">
        <img src="/InbornFood_logo.jpeg" alt="InbornFood" width="54" height="54">
        <span><strong>InbornFood</strong><small>Store administration</small></span>
      </a>
      <div class="login-heading">
        <p class="eyebrow">Secure access</p>
        <h1>Welcome back</h1>
        <p>Sign in to manage customer orders.</p>
      </div>

      <?php if (!$configured): ?>
        <div class="alert alert-warning">
          Admin access is disabled until <code>F2H_ADMIN_USERNAME</code> and
          <code>F2H_ADMIN_PASSWORD_HASH</code> are configured.
        </div>
      <?php endif; ?>
      <?php if ($loginError !== ''): ?>
        <div class="alert alert-error" role="alert"><?= h($loginError) ?></div>
      <?php endif; ?>

      <form method="post" action="/admin/" class="login-form">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <label>
          <span>Username</span>
          <input name="username" autocomplete="username" maxlength="80" required <?= $configured ? '' : 'disabled' ?>>
        </label>
        <label>
          <span>Password</span>
          <input name="password" type="password" autocomplete="current-password" required <?= $configured ? '' : 'disabled' ?>>
        </label>
        <button class="primary-button" type="submit" <?= $configured ? '' : 'disabled' ?>>Sign in</button>
      </form>
      <a class="back-link" href="/">← Return to storefront</a>
    </section>
  </main>
</body>
</html>
    <?php
    exit;
}

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refund_order') {
    if (!validCsrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
    } else {
        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
        $confirmed = (string) ($_POST['confirm_refund'] ?? '') === 'yes';
        if ($orderId === false || $orderId < 1 || !$confirmed) {
            flash('error', 'Confirm the full refund before continuing.');
        } else {
            try {
                $refund = requestRazorpayRefund($orderId);
                flash(
                    'success',
                    $refund['status'] === 'processed'
                        ? "Order #{$orderId} was refunded successfully."
                        : "Refund started for order #{$orderId}. Razorpay confirmation is pending."
                );
            } catch (DomainException $error) {
                flash('error', $error->getMessage());
            } catch (Throwable $error) {
            error_log('InbornFood admin refund error: ' . $error->getMessage());
                flash('error', $error->getMessage() === 'Razorpay could not start the refund. You can safely retry.'
                    ? $error->getMessage()
                    : 'The refund could not be started. Check the server log before retrying.');
            }
        }
    }
    header('Location: /admin/' . safeReturnQuery($_POST['return_query'] ?? ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    if (!validCsrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
    } else {
        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
        $newStatus = (string) ($_POST['status'] ?? '');
        if ($orderId === false || $orderId < 1 || !in_array($newStatus, ADMIN_STATUSES, true)) {
            flash('error', 'Invalid order status request.');
        } else {
            try {
                $changed = updateOrderStatusWithInventory($orderId, $newStatus);
                flash(
                    $changed ? 'success' : 'info',
                    $changed
                        ? "Order #{$orderId} updated to " . statusLabel($newStatus) . '.'
                        : "Order #{$orderId} was already " . statusLabel($newStatus) . '.'
                );
            } catch (DomainException $error) {
                flash('error', $error->getMessage());
            } catch (Throwable $error) {
            error_log('InbornFood admin update error: ' . $error->getMessage());
                flash('error', 'The order could not be updated.');
            }
        }
    }
    header('Location: /admin/' . safeReturnQuery($_POST['return_query'] ?? ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_delivery') {
    if (!validCsrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
    } else {
        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
        $courierName = trim((string) ($_POST['courier_name'] ?? ''));
        $trackingNumber = trim((string) ($_POST['tracking_number'] ?? ''));
        $trackingUrl = trim((string) ($_POST['tracking_url'] ?? ''));
        $estimatedDate = trim((string) ($_POST['estimated_delivery_date'] ?? ''));
        $validDate = $estimatedDate === '' || DateTimeImmutable::createFromFormat('!Y-m-d', $estimatedDate)?->format('Y-m-d') === $estimatedDate;
        if ($orderId === false || $orderId < 1
            || mb_strlen($courierName) > 100
            || mb_strlen($trackingNumber) > 100
            || mb_strlen($trackingUrl) > 500
            || ($trackingUrl !== '' && filter_var($trackingUrl, FILTER_VALIDATE_URL) === false)
            || ($trackingUrl !== '' && !in_array(strtolower((string) parse_url($trackingUrl, PHP_URL_SCHEME)), ['http', 'https'], true))
            || !$validDate
        ) {
            flash('error', 'Enter valid delivery tracking details.');
        } else {
            try {
                updateDeliveryDetails($orderId, $courierName, $trackingNumber, $trackingUrl, $estimatedDate === '' ? null : $estimatedDate);
                flash('success', "Delivery details saved for order #{$orderId}.");
            } catch (DomainException $error) {
                flash('error', $error->getMessage());
            } catch (Throwable $error) {
            error_log('InbornFood delivery update error: ' . $error->getMessage());
                flash('error', 'The delivery details could not be saved.');
            }
        }
    }
    header('Location: /admin/' . safeReturnQuery($_POST['return_query'] ?? ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manage_pincode') {
    if (!validCsrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
    } else {
        $pincode = preg_replace('/\D/', '', (string) ($_POST['pincode'] ?? '')) ?? '';
        $mode = (string) ($_POST['mode'] ?? 'add');
        $areaName = trim((string) ($_POST['area_name'] ?? ''));
        $deliveryFee = filter_var($_POST['delivery_fee'] ?? null, FILTER_VALIDATE_FLOAT);
        $minDays = filter_var($_POST['min_delivery_days'] ?? null, FILTER_VALIDATE_INT);
        $maxDays = filter_var($_POST['max_delivery_days'] ?? null, FILTER_VALIDATE_INT);
        $invalidRule = $mode === 'add' && (
            $deliveryFee === false || $deliveryFee < 0 || $deliveryFee > 10000
            || $minDays === false || $minDays < 1 || $minDays > 30
            || $maxDays === false || $maxDays < $minDays || $maxDays > 45
        );
        if (!preg_match('/^[1-9]\d{5}$/', $pincode)
            || mb_strlen($areaName) > 100
            || !in_array($mode, ['add', 'delete'], true)
            || $invalidRule
        ) {
            flash('error', 'Enter a valid PIN code, delivery fee and delivery-day range.');
        } else {
            try {
                if ($mode === 'delete') {
                    $statement = database()->prepare('DELETE FROM delivery_pincodes WHERE pincode = ?');
                    $statement->bind_param('s', $pincode);
                    $statement->execute();
                    $statement->close();
                    flash('success', "PIN code {$pincode} removed.");
                } else {
                    $statement = database()->prepare(
                        'INSERT INTO delivery_pincodes
                         (pincode, area_name, delivery_fee, min_delivery_days, max_delivery_days, active)
                         VALUES (?, ?, ?, ?, ?, 1)
                         ON DUPLICATE KEY UPDATE area_name = VALUES(area_name),
                            delivery_fee = VALUES(delivery_fee),
                            min_delivery_days = VALUES(min_delivery_days),
                            max_delivery_days = VALUES(max_delivery_days),
                            active = 1'
                    );
                    $statement->bind_param('ssdii', $pincode, $areaName, $deliveryFee, $minDays, $maxDays);
                    $statement->execute();
                    $statement->close();
                    flash('success', "Delivery override saved for PIN code {$pincode}.");
                }
            } catch (Throwable $error) {
            error_log('InbornFood pincode update error: ' . $error->getMessage());
                flash('error', 'The serviceable PIN code could not be updated.');
            }
        }
    }
    header('Location: /admin/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_state_rate') {
    if (!validCsrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
    } else {
        $stateName = trim((string) ($_POST['state_name'] ?? ''));
        $allowedStates = ['Tamil Nadu', 'Kerala', 'Karnataka', 'Telangana', '*'];
        $deliveryFee = filter_var($_POST['delivery_fee'] ?? null, FILTER_VALIDATE_FLOAT);
        $minDays = filter_var($_POST['min_delivery_days'] ?? null, FILTER_VALIDATE_INT);
        $maxDays = filter_var($_POST['max_delivery_days'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($stateName, $allowedStates, true)
            || $deliveryFee === false || $deliveryFee < 0 || $deliveryFee > 10000
            || $minDays === false || $minDays < 1 || $minDays > 30
            || $maxDays === false || $maxDays < $minDays || $maxDays > 45
        ) {
            flash('error', 'Enter a valid delivery fee and working-day range.');
        } else {
            try {
                $statement = database()->prepare(
                    'INSERT INTO delivery_state_rates
                     (state_name, delivery_fee, min_delivery_days, max_delivery_days, active)
                     VALUES (?, ?, ?, ?, 1)
                     ON DUPLICATE KEY UPDATE delivery_fee = VALUES(delivery_fee),
                        min_delivery_days = VALUES(min_delivery_days),
                        max_delivery_days = VALUES(max_delivery_days), active = 1'
                );
                $statement->bind_param('sdii', $stateName, $deliveryFee, $minDays, $maxDays);
                $statement->execute();
                $statement->close();
                $label = $stateName === '*' ? 'All other states' : $stateName;
                flash('success', "Delivery pricing updated for {$label}.");
            } catch (Throwable $error) {
                error_log('InbornFood state delivery rate update error: ' . $error->getMessage());
                flash('error', 'The state delivery rate could not be updated. Run the state pricing migration first.');
            }
        }
    }
    header('Location: /admin/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manage_blocked_pincode') {
    if (!validCsrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
    } else {
        $pincode = preg_replace('/\D/', '', (string) ($_POST['pincode'] ?? '')) ?? '';
        $mode = (string) ($_POST['mode'] ?? 'add');
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (!preg_match('/^[1-9]\d{5}$/', $pincode)
            || !in_array($mode, ['add', 'delete'], true)
            || mb_strlen($reason) > 150
        ) {
            flash('error', 'Enter a valid unsupported PIN code and a short reason.');
        } else {
            try {
                if ($mode === 'delete') {
                    $statement = database()->prepare('DELETE FROM delivery_blocked_pincodes WHERE pincode = ?');
                    $statement->bind_param('s', $pincode);
                    $statement->execute();
                    $statement->close();
                    flash('success', "PIN code {$pincode} is available for delivery again.");
                } else {
                    $statement = database()->prepare(
                        'INSERT INTO delivery_blocked_pincodes (pincode, reason, active)
                         VALUES (?, ?, 1)
                         ON DUPLICATE KEY UPDATE reason = VALUES(reason), active = 1'
                    );
                    $statement->bind_param('ss', $pincode, $reason);
                    $statement->execute();
                    $statement->close();
                    flash('success', "Delivery is now blocked for PIN code {$pincode}.");
                }
            } catch (Throwable $error) {
            error_log('InbornFood blocked pincode update error: ' . $error->getMessage());
                flash('error', 'The unsupported PIN code could not be updated.');
            }
        }
    }
    header('Location: /admin/');
    exit;
}

$search = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$status = in_array($status, ADMIN_STATUSES, true) ? $status : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$databaseError = '';
$orders = [];
$deliveryZones = [];
$blockedZones = [];
$stateRates = [];
$orderHistory = [];
$totalOrders = 0;
$metrics = ['today_orders' => 0, 'pending_orders' => 0, 'month_sales' => 0, 'month_orders' => 0];

try {
    try {
        $stateRates = database()->query(
            "SELECT state_name, delivery_fee, min_delivery_days, max_delivery_days
             FROM delivery_state_rates
             WHERE active = 1 AND state_name IN ('Tamil Nadu', 'Kerala', 'Karnataka', 'Telangana', '*')
             ORDER BY FIELD(state_name, 'Tamil Nadu', 'Kerala', 'Karnataka', 'Telangana', '*')"
        )->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $error) {
        error_log('InbornFood state delivery rates unavailable: ' . $error->getMessage());
    }
    $deliveryZones = database()->query(
        'SELECT pincode, area_name, delivery_fee, min_delivery_days, max_delivery_days
         FROM delivery_pincodes WHERE active = 1 ORDER BY pincode'
    )->fetch_all(MYSQLI_ASSOC);
    $blockedZones = database()->query(
        'SELECT pincode, reason FROM delivery_blocked_pincodes WHERE active = 1 ORDER BY pincode'
    )->fetch_all(MYSQLI_ASSOC);
    $metricsResult = database()->query(
        "SELECT
            SUM(created_at >= CURDATE()) AS today_orders,
            SUM(status = 'pending') AS pending_orders,
            COALESCE(SUM(CASE WHEN created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                AND status != 'cancelled' THEN total ELSE 0 END), 0) AS month_sales,
            SUM(created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND status != 'cancelled') AS month_orders
         FROM orders"
    );
    $metrics = array_merge($metrics, $metricsResult->fetch_assoc() ?: []);

    $conditions = [];
    $types = '';
    $parameters = [];
    if ($status !== '') {
        $conditions[] = 'orders.status = ?';
        $types .= 's';
        $parameters[] = $status;
    }
    if ($search !== '') {
        $conditions[] = '(CAST(orders.id AS CHAR) = ? OR customer_name LIKE ? OR phone LIKE ?)';
        $types .= 'sss';
        $parameters[] = ltrim($search, '#');
        $likeSearch = '%' . $search . '%';
        $parameters[] = $likeSearch;
        $parameters[] = $likeSearch;
    }
    $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

    $countStatement = database()->prepare('SELECT COUNT(*) AS count FROM orders' . $where);
    if ($types !== '') {
        $countStatement->bind_param($types, ...$parameters);
    }
    $countStatement->execute();
    $totalOrders = (int) ($countStatement->get_result()->fetch_assoc()['count'] ?? 0);
    $countStatement->close();

    $totalPages = max(1, (int) ceil($totalOrders / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $query = 'SELECT orders.id, customer_name, phone, address, postal_code, payment_method, payment_status,
                     gateway_order_id, orders.gateway_payment_id, order_details,
                     subtotal, delivery_fee, coupon_code, coupon_discount,
                     combo_discount, discount_total, total, orders.status,
                     courier_name, tracking_number, tracking_url, estimated_delivery_date,
                     orders.created_at, orders.updated_at
                     , payment_refunds.gateway_refund_id AS refund_id,
                     payment_refunds.status AS refund_status,
                     payment_refunds.failure_reason AS refund_failure_reason
              FROM orders
              LEFT JOIN payment_refunds ON payment_refunds.order_id = orders.id'
              . $where . ' ORDER BY orders.created_at DESC, orders.id DESC LIMIT ? OFFSET ?';
    $queryStatement = database()->prepare($query);
    $queryTypes = $types . 'ii';
    $queryParameters = [...$parameters, $perPage, $offset];
    $queryStatement->bind_param($queryTypes, ...$queryParameters);
    $queryStatement->execute();
    $orders = $queryStatement->get_result()->fetch_all(MYSQLI_ASSOC);
    $queryStatement->close();

    if ($orders !== []) {
        $visibleOrderIds = array_map(static fn (array $order): int => (int) $order['id'], $orders);
        $historyPlaceholders = implode(',', array_fill(0, count($visibleOrderIds), '?'));
        $historyTypes = str_repeat('i', count($visibleOrderIds));
        $historyStatement = database()->prepare(
            "SELECT order_id, status, source, created_at
             FROM order_status_history
             WHERE order_id IN ({$historyPlaceholders})
             ORDER BY created_at, id"
        );
        $historyStatement->bind_param($historyTypes, ...$visibleOrderIds);
        $historyStatement->execute();
        foreach ($historyStatement->get_result()->fetch_all(MYSQLI_ASSOC) as $event) {
            $orderHistory[(int) $event['order_id']][] = $event;
        }
        $historyStatement->close();
    }
} catch (Throwable $error) {
    error_log('InbornFood admin dashboard error: ' . $error->getMessage());
    $databaseError = 'Orders are temporarily unavailable. Check the database configuration.';
    $totalPages = 1;
}

$flash = takeFlash();
$currentQuery = http_build_query(array_filter([
    'q' => $search,
    'status' => $status,
    'page' => $page > 1 ? $page : null,
], static fn ($value) => $value !== '' && $value !== null));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Orders | InbornFood Admin</title>
  <link rel="stylesheet" href="/admin/styles.css">
</head>
<body>
  <header class="admin-header">
    <a class="admin-brand" href="/admin/">
      <img src="/InbornFood_logo.jpeg" alt="InbornFood" width="44" height="44">
      <span><strong>InbornFood</strong><small>Admin</small></span>
    </a>
    <div class="admin-actions">
      <a href="/admin/products.php">Products</a>
      <a href="/admin/promotions.php">Promotions</a>
      <a href="/" target="_blank" rel="noopener">View store ↗</a>
      <form method="post" action="/admin/logout.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <button type="submit">Sign out</button>
      </form>
    </div>
  </header>

  <main class="dashboard">
    <div class="page-heading">
      <div><p class="eyebrow">Store operations</p><h1>Orders</h1><p>Track, verify and fulfil customer orders.</p></div>
      <a class="secondary-button" href="/admin/export.php?<?= h(http_build_query(['q' => $search, 'status' => $status, 'token' => csrfToken()])) ?>">Export CSV</a>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?>" role="status"><?= h($flash['message']) ?></div>
    <?php endif; ?>
    <?php if ($databaseError !== ''): ?>
      <div class="alert alert-error" role="alert"><?= h($databaseError) ?></div>
    <?php endif; ?>

    <section class="metrics" aria-label="Order summary">
      <article><span>Orders today</span><strong><?= number_format((int) $metrics['today_orders']) ?></strong><small>New since midnight</small></article>
      <article><span>Needs attention</span><strong><?= number_format((int) $metrics['pending_orders']) ?></strong><small>Pending confirmation</small></article>
      <article><span>This month</span><strong>₹<?= number_format((float) $metrics['month_sales'], 0) ?></strong><small>Excluding cancelled orders</small></article>
      <article><span>Monthly orders</span><strong><?= number_format((int) $metrics['month_orders']) ?></strong><small>Active orders</small></article>
    </section>

    <section class="serviceability-panel">
      <div>
        <p class="eyebrow">Delivery coverage</p>
        <h2>State delivery rates</h2>
        <p>Update customer delivery charges and estimates here. The default rule applies to every state not listed separately.</p>
      </div>
      <?php if ($stateRates === []): ?>
        <div class="alert alert-error">State pricing is unavailable. Run the state delivery pricing migration first.</div>
      <?php else: ?>
        <div class="state-rate-list">
          <?php foreach ($stateRates as $rate): ?>
            <form method="post" action="/admin/" class="pincode-form state-rate-form">
              <input type="hidden" name="action" value="update_state_rate">
              <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
              <input type="hidden" name="state_name" value="<?= h($rate['state_name']) ?>">
              <strong><?= h($rate['state_name'] === '*' ? 'All other states' : $rate['state_name']) ?></strong>
              <label><span>Fee ₹</span><input name="delivery_fee" type="number" min="0" max="10000" step="1" value="<?= h($rate['delivery_fee']) ?>" required></label>
              <label><span>Minimum working days</span><input name="min_delivery_days" type="number" min="1" max="30" value="<?= h($rate['min_delivery_days']) ?>" required></label>
              <label><span>Maximum working days</span><input name="max_delivery_days" type="number" min="1" max="45" value="<?= h($rate['max_delivery_days']) ?>" required></label>
              <button type="submit">Update</button>
            </form>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="serviceability-divider"></div>
      <div>
        <p class="eyebrow">Special PIN-code rules</p>
        <h2>Delivery fee and timing overrides</h2>
        <p>State pricing applies automatically. Add a rule here only when a specific PIN code needs a different fee or working-day estimate.</p>
      </div>
      <form method="post" action="/admin/" class="pincode-form">
        <input type="hidden" name="action" value="manage_pincode">
        <input type="hidden" name="mode" value="add">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input name="pincode" inputmode="numeric" maxlength="6" pattern="[1-9][0-9]{5}" placeholder="PIN code" required>
        <input name="area_name" maxlength="100" placeholder="Area name (optional)">
        <input name="delivery_fee" type="number" min="0" max="10000" step="1" value="0" placeholder="Fee ₹" required>
        <input name="min_delivery_days" type="number" min="1" max="30" value="2" aria-label="Minimum delivery days" required>
        <input name="max_delivery_days" type="number" min="1" max="45" value="5" aria-label="Maximum delivery days" required>
        <button type="submit">Save rule</button>
      </form>
      <?php if ($deliveryZones !== []): ?>
        <div class="pincode-list">
          <?php foreach ($deliveryZones as $zone): ?>
            <span>
              <?= h($zone['pincode']) ?><?= $zone['area_name'] !== '' ? ' · ' . h($zone['area_name']) : '' ?>
              · <?= (float) $zone['delivery_fee'] > 0 ? '₹' . number_format((float) $zone['delivery_fee'], 0) : 'Free' ?>
              · <?= h($zone['min_delivery_days']) ?>–<?= h($zone['max_delivery_days']) ?> days
              <form method="post" action="/admin/">
                <input type="hidden" name="action" value="manage_pincode">
                <input type="hidden" name="mode" value="delete">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="pincode" value="<?= h($zone['pincode']) ?>">
                <button type="submit" aria-label="Remove PIN code <?= h($zone['pincode']) ?>">×</button>
              </form>
            </span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="serviceability-divider"></div>
      <div>
        <p class="eyebrow">Delivery exclusions</p>
        <h2>Unsupported PIN codes</h2>
        <p><?= $blockedZones === [] ? 'No PIN codes are blocked.' : count($blockedZones) . ' PIN code(s) are currently blocked at checkout.' ?></p>
      </div>
      <form method="post" action="/admin/" class="pincode-form">
        <input type="hidden" name="action" value="manage_blocked_pincode">
        <input type="hidden" name="mode" value="add">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input name="pincode" inputmode="numeric" maxlength="6" pattern="[1-9][0-9]{5}" placeholder="Unsupported PIN code" required>
        <input name="reason" maxlength="150" placeholder="Internal reason (optional)">
        <button type="submit">Block delivery</button>
      </form>
      <?php if ($blockedZones !== []): ?>
        <div class="pincode-list">
          <?php foreach ($blockedZones as $zone): ?>
            <span>
              <?= h($zone['pincode']) ?><?= $zone['reason'] !== '' ? ' · ' . h($zone['reason']) : '' ?>
              <form method="post" action="/admin/">
                <input type="hidden" name="action" value="manage_blocked_pincode">
                <input type="hidden" name="mode" value="delete">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="pincode" value="<?= h($zone['pincode']) ?>">
                <button type="submit" aria-label="Allow PIN code <?= h($zone['pincode']) ?>">×</button>
              </form>
            </span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="orders-panel">
      <form class="filters" method="get" action="/admin/">
        <label class="search-field">
          <span class="sr-only">Search orders</span>
          <input type="search" name="q" value="<?= h($search) ?>" maxlength="100" placeholder="Search order, customer or phone">
        </label>
        <label>
          <span class="sr-only">Filter by status</span>
          <select name="status">
            <option value="">All statuses</option>
            <?php foreach (ADMIN_STATUSES as $filterStatus): ?>
              <option value="<?= h($filterStatus) ?>" <?= $status === $filterStatus ? 'selected' : '' ?>><?= h(statusLabel($filterStatus)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="primary-button compact" type="submit">Apply</button>
        <?php if ($search !== '' || $status !== ''): ?><a class="clear-link" href="/admin/">Clear</a><?php endif; ?>
      </form>

      <div class="panel-meta">
        <strong><?= number_format($totalOrders) ?> order<?= $totalOrders === 1 ? '' : 's' ?></strong>
        <span>Page <?= $page ?> of <?= $totalPages ?></span>
      </div>

      <?php if ($orders === [] && $databaseError === ''): ?>
        <div class="empty-state"><span>📦</span><h2>No orders found</h2><p>New customer orders will appear here.</p></div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Order</th><th>Customer</th><th>Items</th><th>Total</th><th>Status</th><th>Placed</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $order):
                $items = normalizedOrderItems((string) $order['order_details']);
                $whatsappOptions = whatsappMessageOptions($order);
            ?>
              <tr>
                <td data-label="Order">
                  <strong>#<?= h($order['id']) ?></strong>
                  <details>
                    <summary>View details</summary>
                    <div class="order-detail">
                      <strong>Delivery address</strong>
                      <p><?= nl2br(h($order['address'])) ?></p>
                      <?php if ($order['postal_code'] !== null): ?><strong>PIN code</strong><p><?= h($order['postal_code']) ?></p><?php endif; ?>
                      <strong>Payment</strong>
                      <p><?= h($order['payment_method']) ?> · <?= h(ucwords(str_replace('_', ' ', (string) $order['payment_status']))) ?></p>
                      <strong>Delivery charge</strong>
                      <p><?= (float) $order['delivery_fee'] > 0 ? '₹' . number_format((float) $order['delivery_fee'], 0) : 'Free' ?></p>
                      <?php if ((float) $order['discount_total'] > 0): ?>
                        <strong>Promotion savings</strong>
                        <p>
                          −₹<?= number_format((float) $order['discount_total'], 0) ?>
                          <?= $order['coupon_code'] !== '' ? ' · ' . h($order['coupon_code']) : '' ?>
                        </p>
                      <?php endif; ?>
                      <?php if ($order['gateway_order_id'] !== null): ?><strong>Gateway order</strong><p><?= h($order['gateway_order_id']) ?></p><?php endif; ?>
                      <?php if ($order['gateway_payment_id'] !== null): ?><strong>Gateway payment</strong><p><?= h($order['gateway_payment_id']) ?></p><?php endif; ?>
                      <?php if ($order['refund_status'] !== null): ?>
                        <strong>Refund</strong>
                        <p>
                          <?= h(ucfirst((string) $order['refund_status'])) ?>
                          <?= $order['refund_id'] ? ' · ' . h($order['refund_id']) : '' ?>
                        </p>
                        <?php if ($order['refund_status'] === 'failed' && $order['refund_failure_reason'] !== ''): ?>
                          <p class="refund-error"><?= h($order['refund_failure_reason']) ?></p>
                        <?php endif; ?>
                      <?php endif; ?>
                      <?php if ($order['status'] === 'cancelled'
                          && $order['payment_status'] === 'paid'
                          && ($order['refund_status'] === null || $order['refund_status'] === 'failed')): ?>
                        <form method="post" action="/admin/" class="refund-form"
                              onsubmit="return confirm('Refund the full ₹<?= number_format((float) $order['total'], 0) ?> for order #<?= h($order['id']) ?>?');">
                          <input type="hidden" name="action" value="refund_order">
                          <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                          <input type="hidden" name="order_id" value="<?= h($order['id']) ?>">
                          <input type="hidden" name="return_query" value="<?= h($currentQuery) ?>">
                          <input type="hidden" name="confirm_refund" value="yes">
                          <button type="submit">Refund full ₹<?= number_format((float) $order['total'], 0) ?></button>
                        </form>
                      <?php endif; ?>
                      <strong>Last updated</strong>
                      <p><?= h(date('d M Y, g:i A', strtotime((string) $order['updated_at']))) ?></p>
                      <strong>Delivery tracking</strong>
                      <form method="post" action="/admin/" class="delivery-form">
                        <input type="hidden" name="action" value="update_delivery">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="order_id" value="<?= h($order['id']) ?>">
                        <input type="hidden" name="return_query" value="<?= h($currentQuery) ?>">
                        <input name="courier_name" value="<?= h($order['courier_name']) ?>" maxlength="100" placeholder="Courier name">
                        <input name="tracking_number" value="<?= h($order['tracking_number']) ?>" maxlength="100" placeholder="Tracking number">
                        <input name="tracking_url" type="url" value="<?= h($order['tracking_url']) ?>" maxlength="500" placeholder="https://courier.example/track">
                        <label>Estimated delivery <input name="estimated_delivery_date" type="date" value="<?= h($order['estimated_delivery_date']) ?>"></label>
                        <button type="submit">Save delivery</button>
                      </form>
                      <?php if (!empty($orderHistory[(int) $order['id']])): ?>
                        <strong>Status history</strong>
                        <ol class="status-history">
                          <?php foreach ($orderHistory[(int) $order['id']] as $event): ?>
                            <li><span><?= h(statusLabel((string) $event['status'])) ?></span><time><?= h(date('d M, g:i A', strtotime((string) $event['created_at']))) ?></time></li>
                          <?php endforeach; ?>
                        </ol>
                      <?php endif; ?>
                    </div>
                  </details>
                </td>
                <td data-label="Customer">
                  <strong><?= h($order['customer_name']) ?></strong>
                  <a href="tel:+91<?= h($order['phone']) ?>"><?= h($order['phone']) ?></a>
                  <form class="whatsapp-form" method="post" action="/admin/whatsapp.php" target="_blank">
                    <input type="hidden" name="token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="order_id" value="<?= h($order['id']) ?>">
                    <select name="template" aria-label="WhatsApp message for order <?= h($order['id']) ?>">
                      <?php foreach ($whatsappOptions as $template => $label): ?>
                        <option value="<?= h($template) ?>"><?= h($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit">WhatsApp ↗</button>
                  </form>
                </td>
                <td data-label="Items">
                  <ul class="order-items">
                    <?php foreach ($items as $item): ?>
                      <li>
                        <span><?= h($item['name'] ?? 'Item') ?><?= !empty($item['variant']) ? ' · ' . h($item['variant']) : '' ?></span>
                        <?php if (($item['quantity'] ?? '') !== ''): ?><strong>× <?= h($item['quantity']) ?></strong><?php endif; ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </td>
                <td data-label="Total"><strong>₹<?= number_format((float) $order['total'], 0) ?></strong></td>
                <td data-label="Status">
                  <form method="post" action="/admin/" class="status-form">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="order_id" value="<?= h($order['id']) ?>">
                    <input type="hidden" name="return_query" value="<?= h($currentQuery) ?>">
                    <select class="status-select status-<?= h($order['status']) ?>" name="status" aria-label="Status for order <?= h($order['id']) ?>">
                      <?php foreach (ADMIN_STATUSES as $orderStatus): ?>
                        <option value="<?= h($orderStatus) ?>" <?= $order['status'] === $orderStatus ? 'selected' : '' ?>><?= h(statusLabel($orderStatus)) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit">Save</button>
                  </form>
                </td>
                <td data-label="Placed"><time datetime="<?= h(date('c', strtotime((string) $order['created_at']))) ?>"><?= h(date('d M Y', strtotime((string) $order['created_at']))) ?><small><?= h(date('g:i A', strtotime((string) $order['created_at']))) ?></small></time></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Orders pagination">
          <?php if ($page > 1): ?><a href="?<?= h(http_build_query(['q' => $search, 'status' => $status, 'page' => $page - 1])) ?>">← Previous</a><?php endif; ?>
          <span>Page <?= $page ?> of <?= $totalPages ?></span>
          <?php if ($page < $totalPages): ?><a href="?<?= h(http_build_query(['q' => $search, 'status' => $status, 'page' => $page + 1])) ?>">Next →</a><?php endif; ?>
        </nav>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
