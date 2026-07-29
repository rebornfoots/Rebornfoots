<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');

$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('INBORNFOOT_TRACKING');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/track-order',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, private');

const LOOKUP_LIMIT = 5;
const LOOKUP_WINDOW_SECONDS = 600;
const ORDER_STAGES = ['pending', 'confirmed', 'paid', 'packed', 'shipped', 'delivered'];

function escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function trackingCsrf(): string
{
    if (!isset($_SESSION['tracking_csrf'])) {
        $_SESSION['tracking_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['tracking_csrf'];
}

function lookupAttempts(): array
{
    $cutoff = time() - LOOKUP_WINDOW_SECONDS;
    $attempts = array_filter(
        is_array($_SESSION['lookup_attempts'] ?? null) ? $_SESSION['lookup_attempts'] : [],
        static fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp > $cutoff
    );
    $_SESSION['lookup_attempts'] = array_values($attempts);
    return $_SESSION['lookup_attempts'];
}

function registerFailedLookup(): void
{
    $attempts = lookupAttempts();
    $attempts[] = time();
    $_SESSION['lookup_attempts'] = $attempts;
}

function trackingItems(string $details): array
{
    try {
        $items = json_decode($details, true, 32, JSON_THROW_ON_ERROR);
        if (is_array($items)) {
            return array_values($items);
        }
    } catch (JsonException) {
        // Legacy orders stored a human-readable text summary.
    }
    return [['name' => $details, 'variant' => '', 'quantity' => '', 'subtotal' => '']];
}

$order = null;
$history = [];
$error = '';
$submittedOrderId = trim((string) ($_POST['order_id'] ?? ''));
$submittedPhone = preg_replace('/\D/', '', (string) ($_POST['phone'] ?? '')) ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $attempts = lookupAttempts();
    if (count($attempts) >= LOOKUP_LIMIT) {
        $retryAfter = max(1, LOOKUP_WINDOW_SECONDS - (time() - (int) min($attempts)));
        header('Retry-After: ' . $retryAfter);
        $error = 'Too many unsuccessful attempts. Please wait a few minutes and try again.';
    } elseif (!isset($_POST['csrf_token'])
        || !is_string($_POST['csrf_token'])
        || !hash_equals(trackingCsrf(), $_POST['csrf_token'])
    ) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $orderId = filter_var(ltrim($submittedOrderId, '#'), FILTER_VALIDATE_INT);
        if ($orderId === false || $orderId < 1 || !preg_match('/^[6-9]\d{9}$/', $submittedPhone)) {
            registerFailedLookup();
            usleep(300000);
            $error = 'We could not find an order matching those details.';
        } else {
            try {
                $host = getenv('F2H_DB_HOST') ?: '';
                $name = getenv('F2H_DB_NAME') ?: '';
                $user = getenv('F2H_DB_USER') ?: '';
                $password = getenv('F2H_DB_PASSWORD') ?: '';
                if ($host === '' || $name === '' || $user === '') {
                    throw new RuntimeException('Database configuration is missing.');
                }

                mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
                $database = new mysqli($host, $user, $password, $name);
                $database->set_charset('utf8mb4');
                $statement = $database->prepare(
                    'SELECT id, customer_name, payment_method, order_details, total, status, created_at, updated_at
                     FROM orders WHERE id = ? AND phone = ? LIMIT 1'
                );
                $statement->bind_param('is', $orderId, $submittedPhone);
                $statement->execute();
                $order = $statement->get_result()->fetch_assoc() ?: null;
                $statement->close();

                if ($order === null) {
                    registerFailedLookup();
                    usleep(300000);
                    $error = 'We could not find an order matching those details.';
                } else {
                    $historyStatement = $database->prepare(
                        'SELECT status, source, created_at
                         FROM order_status_history WHERE order_id = ? ORDER BY created_at, id'
                    );
                    $historyStatement->bind_param('i', $orderId);
                    $historyStatement->execute();
                    $history = $historyStatement->get_result()->fetch_all(MYSQLI_ASSOC);
                    $historyStatement->close();
                    $_SESSION['lookup_attempts'] = [];
                }
                $database->close();
            } catch (Throwable $exception) {
                error_log('InbornFoot tracking error: ' . $exception->getMessage());
                $error = 'Order tracking is temporarily unavailable. Please try again shortly.';
            }
        }
    }
}

$stageIndex = $order && $order['status'] !== 'cancelled'
    ? array_search((string) $order['status'], ORDER_STAGES, true)
    : false;
$historyByStatus = [];
foreach ($history as $event) {
    $eventStatus = (string) ($event['status'] ?? '');
    if (!isset($historyByStatus[$eventStatus])) {
        $historyByStatus[$eventStatus] = (string) $event['created_at'];
    }
}
$reorderItems = $order
    ? array_values(array_filter(
        trackingItems((string) $order['order_details']),
        static fn (array $item): bool => isset($item['productId'], $item['quantity'])
            && is_string($item['productId'])
            && preg_match('/^[a-z0-9-]{2,64}$/', $item['productId']) === 1
            && filter_var($item['quantity'], FILTER_VALIDATE_INT) !== false
            && (int) $item['quantity'] > 0
    ))
    : [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <meta name="theme-color" content="#174f35">
  <title>Track Your Order | InbornFoot</title>
  <link rel="stylesheet" href="/track-order/styles.css">
  <script src="/track-order/receipt.js" defer></script>
</head>
<body>
  <header class="tracking-header">
    <a class="brand" href="/">
      <img src="/logo.png" width="48" height="48" alt="">
      <span><strong>InbornFoot</strong><small>From farm. With care.</small></span>
    </a>
    <a href="/">← Back to store</a>
  </header>

  <main>
    <section class="lookup-panel">
      <div class="lookup-intro">
        <p class="eyebrow">Order tracking</p>
        <h1>Where is my order?</h1>
        <p>Enter the order number and mobile number used during checkout.</p>
      </div>
      <form method="post" action="/track-order/" class="lookup-form">
        <input type="hidden" name="csrf_token" value="<?= escape(trackingCsrf()) ?>">
        <label>
          <span>Order number</span>
          <input name="order_id" inputmode="numeric" value="<?= escape($submittedOrderId) ?>" placeholder="For example, 1024" maxlength="20" required>
        </label>
        <label>
          <span>Mobile number</span>
          <input name="phone" type="tel" inputmode="numeric" value="<?= escape($submittedPhone) ?>" placeholder="10-digit mobile number" pattern="[6-9][0-9]{9}" maxlength="10" required>
        </label>
        <button type="submit">Track order</button>
      </form>
      <?php if ($error !== ''): ?><div class="message error" role="alert"><?= escape($error) ?></div><?php endif; ?>
      <p class="privacy-note">For your privacy, both details must match our records.</p>
    </section>

    <?php if ($order): ?>
      <section class="result-panel">
        <div class="result-heading">
          <div>
            <p class="eyebrow">Order #<?= escape($order['id']) ?></p>
            <h2><?= $order['status'] === 'cancelled' ? 'This order was cancelled' : 'Hello, ' . escape(explode(' ', trim((string) $order['customer_name']))[0]) ?></h2>
            <p>Placed on <?= escape(date('d M Y \a\t g:i A', strtotime((string) $order['created_at']))) ?></p>
          </div>
          <div class="result-actions">
            <span class="status status-<?= escape($order['status']) ?>"><?= escape(ucfirst((string) $order['status'])) ?></span>
            <button class="print-receipt" type="button" data-print-receipt>Print receipt</button>
            <?php if ($reorderItems !== []): ?>
              <button class="buy-again" type="button" data-buy-again>Buy again</button>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($order['status'] === 'cancelled'): ?>
          <div class="cancelled-message"><span>×</span><div><strong>Order cancelled</strong><p>If you made a payment or need help, call us at <a href="tel:+918110007172">8110007172</a>.</p></div></div>
        <?php else: ?>
          <ol class="timeline" aria-label="Order progress">
            <?php foreach (ORDER_STAGES as $index => $stage):
              $state = $stageIndex !== false && $index < $stageIndex ? 'complete' : ($index === $stageIndex ? 'current' : 'upcoming');
            ?>
              <li class="<?= $state ?>">
                <span><?= $state === 'complete' ? '✓' : $index + 1 ?></span>
                <strong><?= escape(ucfirst($stage)) ?></strong>
                <?php if (isset($historyByStatus[$stage])): ?>
                  <small><?= escape(date('d M, g:i A', strtotime($historyByStatus[$stage]))) ?></small>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>

        <div class="order-summary">
          <div class="items">
            <h3>Order items</h3>
            <?php foreach (trackingItems((string) $order['order_details']) as $item): ?>
              <article>
                <div>
                  <strong><?= escape($item['name'] ?? 'Item') ?></strong>
                  <?php if (!empty($item['variant'])): ?><small><?= escape($item['variant']) ?></small><?php endif; ?>
                </div>
                <?php if (($item['quantity'] ?? '') !== ''): ?><span>× <?= escape($item['quantity']) ?></span><?php endif; ?>
                <?php if (($item['subtotal'] ?? '') !== ''): ?><strong>₹<?= number_format((float) $item['subtotal'], 0) ?></strong><?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
          <aside>
            <div><span>Order total</span><strong>₹<?= number_format((float) $order['total'], 0) ?></strong></div>
            <div><span>Payment</span><strong><?= escape($order['payment_method']) ?></strong></div>
            <div><span>Last updated</span><strong><?= escape(date('d M Y, g:i A', strtotime((string) $order['updated_at']))) ?></strong></div>
          </aside>
        </div>
      </section>
      <?php if ($reorderItems !== []): ?>
        <script id="reorder-items" type="application/json"><?= json_encode(
            $reorderItems,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?></script>
      <?php endif; ?>
    <?php endif; ?>

    <section class="help-band">
      <div><p class="eyebrow">Need assistance?</p><h2>We’re here to help.</h2><p>Keep your order number ready when you call.</p></div>
      <a href="tel:+918110007172">Call 8110007172</a>
    </section>
  </main>

  <footer>© <?= date('Y') ?> InbornFoot. Your order details are protected.</footer>
</body>
</html>
