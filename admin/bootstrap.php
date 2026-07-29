<?php
declare(strict_types=1);

const ADMIN_STATUSES = ['pending', 'confirmed', 'paid', 'packed', 'shipped', 'delivered', 'cancelled'];

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');

$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_name('INBORNFOOT_ADMIN');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/admin',
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

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function adminIsConfigured(): bool
{
    return (getenv('F2H_ADMIN_USERNAME') ?: '') !== ''
        && (getenv('F2H_ADMIN_PASSWORD_HASH') ?: '') !== '';
}

function adminIsAuthenticated(): bool
{
    return isset($_SESSION['admin_authenticated_at'], $_SESSION['admin_last_seen'])
        && (time() - (int) $_SESSION['admin_last_seen']) <= 1800;
}

function requireAdmin(): void
{
    if (!adminIsAuthenticated()) {
        $_SESSION = [];
        header('Location: /admin/');
        exit;
    }

    $_SESSION['admin_last_seen'] = time();
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function validCsrf(mixed $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals((string) $_SESSION['csrf_token'], $token);
}

function database(): mysqli
{
    static $database;
    if ($database instanceof mysqli) {
        return $database;
    }

    $host = getenv('F2H_DB_HOST') ?: '';
    $name = getenv('F2H_DB_NAME') ?: '';
    $user = getenv('F2H_DB_USER') ?: '';
    $password = getenv('F2H_DB_PASSWORD') ?: '';

    if ($host === '' || $name === '' || $user === '') {
        throw new RuntimeException('Database environment variables are not configured.');
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $database = new mysqli($host, $user, $password, $name);
    $database->set_charset('utf8mb4');
    return $database;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function takeFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function safeReturnQuery(mixed $value): string
{
    if (!is_string($value) || $value === '') {
        return '';
    }

    parse_str(ltrim($value, '?'), $parameters);
    $allowed = [];
    foreach (['q', 'status', 'page'] as $key) {
        if (isset($parameters[$key]) && is_scalar($parameters[$key])) {
            $allowed[$key] = (string) $parameters[$key];
        }
    }
    return $allowed === [] ? '' : '?' . http_build_query($allowed);
}

function normalizedOrderItems(string $details): array
{
    try {
        $items = json_decode($details, true, 32, JSON_THROW_ON_ERROR);
        if (is_array($items)) {
            return $items;
        }
    } catch (JsonException) {
        // Older records used plain text and are rendered as a single legacy item.
    }

    return [['name' => $details, 'variant' => '', 'quantity' => '', 'subtotal' => '']];
}

function statusLabel(string $status): string
{
    return ucfirst($status);
}

function whatsappMessageOptions(array $order): array
{
    $options = [];
    $status = (string) ($order['status'] ?? '');
    $paymentStatus = (string) ($order['payment_status'] ?? '');
    $refundStatus = (string) ($order['refund_status'] ?? '');

    $statusTemplates = [
        'pending' => ['order_received' => 'Order received'],
        'confirmed' => ['order_confirmed' => 'Order confirmed'],
        'paid' => ['payment_confirmed' => 'Payment confirmed'],
        'packed' => ['order_packed' => 'Order packed'],
        'shipped' => ['order_shipped' => 'Order shipped'],
        'delivered' => ['order_delivered' => 'Order delivered'],
        'cancelled' => ['order_cancelled' => 'Order cancelled'],
    ];
    if (isset($statusTemplates[$status])) {
        $options += $statusTemplates[$status];
    }
    if ($paymentStatus === 'refund_pending' || in_array($refundStatus, ['initiating', 'pending'], true)) {
        $options['refund_pending'] = 'Refund initiated';
    } elseif ($paymentStatus === 'refunded' || $refundStatus === 'processed') {
        $options['refund_processed'] = 'Refund completed';
    } elseif ($paymentStatus === 'paid' && !in_array($status, ['paid', 'cancelled'], true)) {
        $options['payment_confirmed'] = 'Payment confirmed';
    }

    return $options;
}

function updateDeliveryDetails(
    int $orderId,
    string $courierName,
    string $trackingNumber,
    string $trackingUrl,
    ?string $estimatedDate
): void {
    $statement = database()->prepare(
        'UPDATE orders
         SET courier_name = ?, tracking_number = ?, tracking_url = ?, estimated_delivery_date = ?
         WHERE id = ?'
    );
    $statement->bind_param('ssssi', $courierName, $trackingNumber, $trackingUrl, $estimatedDate, $orderId);
    $statement->execute();
    if ($statement->affected_rows === 0) {
        $exists = database()->prepare('SELECT id FROM orders WHERE id = ?');
        $exists->bind_param('i', $orderId);
        $exists->execute();
        $found = $exists->get_result()->fetch_assoc();
        $exists->close();
        if (!$found) {
            $statement->close();
            throw new DomainException('The order no longer exists.');
        }
    }
    $statement->close();
}

function requestRazorpayRefund(int $orderId): array
{
    $keyId = getenv('F2H_RAZORPAY_KEY_ID') ?: '';
    $keySecret = getenv('F2H_RAZORPAY_KEY_SECRET') ?: '';
    if ($keyId === '' || $keySecret === '') {
        throw new RuntimeException('Razorpay credentials are not configured.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is unavailable.');
    }

    $database = database();
    $database->begin_transaction();
    try {
        $statement = $database->prepare(
            'SELECT status, payment_status, gateway_payment_id, total
             FROM orders WHERE id = ? FOR UPDATE'
        );
        $statement->bind_param('i', $orderId);
        $statement->execute();
        $order = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!$order) {
            throw new DomainException('The order no longer exists.');
        }
        if ($order['status'] !== 'cancelled' || $order['payment_status'] !== 'paid') {
            throw new DomainException('Only a cancelled, fully paid order can be refunded.');
        }
        $paymentId = (string) $order['gateway_payment_id'];
        if (!preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId)) {
            throw new DomainException('This order does not have a valid Razorpay payment.');
        }
        $amountPaise = (int) round((float) $order['total'] * 100);
        if ($amountPaise < 100) {
            throw new DomainException('The refundable amount is invalid.');
        }

        $existingStatement = $database->prepare(
            'SELECT status, attempt_count FROM payment_refunds WHERE order_id = ? FOR UPDATE'
        );
        $existingStatement->bind_param('i', $orderId);
        $existingStatement->execute();
        $existing = $existingStatement->get_result()->fetch_assoc();
        $existingStatement->close();
        if ($existing && in_array($existing['status'], ['initiating', 'pending', 'processed'], true)) {
            throw new DomainException('A refund for this order is already in progress or completed.');
        }

        $attemptCount = $existing ? (int) $existing['attempt_count'] + 1 : 1;
        $idempotencyKey = 'inbornfoot-refund-' . $orderId . '-' . bin2hex(random_bytes(8));
        if ($existing) {
            $reserve = $database->prepare(
                "UPDATE payment_refunds
                 SET gateway_refund_id = NULL, idempotency_key = ?, attempt_count = ?,
                     status = 'initiating', failure_reason = ''
                 WHERE order_id = ?"
            );
            $reserve->bind_param('sii', $idempotencyKey, $attemptCount, $orderId);
        } else {
            $reserve = $database->prepare(
                "INSERT INTO payment_refunds
                 (order_id, gateway_payment_id, idempotency_key, attempt_count, amount_paise, status)
                 VALUES (?, ?, ?, ?, ?, 'initiating')"
            );
            $reserve->bind_param('issii', $orderId, $paymentId, $idempotencyKey, $attemptCount, $amountPaise);
        }
        $reserve->execute();
        $reserve->close();
        $database->commit();
    } catch (Throwable $error) {
        $database->rollback();
        throw $error;
    }

    $payload = json_encode([
        'amount' => $amountPaise,
        'speed' => 'normal',
        'receipt' => 'if-refund-' . $orderId . '-' . $attemptCount,
        'notes' => ['order_id' => (string) $orderId],
    ], JSON_THROW_ON_ERROR);
    $curl = curl_init('https://api.razorpay.com/v1/payments/' . rawurlencode($paymentId) . '/refund');
    curl_setopt_array($curl, [
        CURLOPT_USERPWD => $keyId . ':' . $keySecret,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Refund-Idempotency: ' . $idempotencyKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);

    try {
        if (!is_string($body) || $httpStatus < 200 || $httpStatus >= 300) {
            $reason = $curlError !== '' ? $curlError : "Razorpay returned HTTP {$httpStatus}.";
            $failed = $database->prepare(
                "UPDATE payment_refunds SET status = 'failed', failure_reason = ? WHERE order_id = ?"
            );
            $reason = mb_substr($reason, 0, 500);
            $failed->bind_param('si', $reason, $orderId);
            $failed->execute();
            $failed->close();
            throw new RuntimeException('Razorpay could not start the refund. You can safely retry.');
        }
        $refund = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        $refundId = (string) ($refund['id'] ?? '');
        $refundStatus = (string) ($refund['status'] ?? '');
        if (!preg_match('/^rfnd_[A-Za-z0-9]+$/', $refundId)
            || ($refund['payment_id'] ?? '') !== $paymentId
            || (int) ($refund['amount'] ?? 0) !== $amountPaise
            || !in_array($refundStatus, ['pending', 'processed'], true)
        ) {
            throw new RuntimeException('Razorpay returned an invalid refund response.');
        }

        $database->begin_transaction();
        $update = $database->prepare(
            'UPDATE payment_refunds
             SET gateway_refund_id = ?, status = ?, failure_reason = ? WHERE order_id = ?'
        );
        $emptyReason = '';
        $update->bind_param('sssi', $refundId, $refundStatus, $emptyReason, $orderId);
        $update->execute();
        $update->close();
        $paymentStatus = $refundStatus === 'processed' ? 'refunded' : 'refund_pending';
        $orderUpdate = $database->prepare('UPDATE orders SET payment_status = ? WHERE id = ?');
        $orderUpdate->bind_param('si', $paymentStatus, $orderId);
        $orderUpdate->execute();
        $orderUpdate->close();
        $database->commit();
        return ['id' => $refundId, 'status' => $refundStatus];
    } catch (Throwable $error) {
        try { $database->rollback(); } catch (Throwable) {}
        try {
            $reason = mb_substr($error->getMessage(), 0, 500);
            $failed = $database->prepare(
                "UPDATE payment_refunds
                 SET status = 'failed', failure_reason = ?
                 WHERE order_id = ? AND status = 'initiating'"
            );
            $failed->bind_param('si', $reason, $orderId);
            $failed->execute();
            $failed->close();
        } catch (Throwable) {
            // Preserve the original error; a webhook can still reconcile the refund.
        }
        throw $error;
    }
}

function updateOrderStatusWithInventory(int $orderId, string $newStatus): bool
{
    $database = database();
    $stockStatuses = ['confirmed', 'paid', 'packed', 'shipped', 'delivered'];
    $database->begin_transaction();

    try {
        $orderStatement = $database->prepare(
            'SELECT status, payment_status, order_details, inventory_deducted
             FROM orders WHERE id = ? FOR UPDATE'
        );
        $orderStatement->bind_param('i', $orderId);
        $orderStatement->execute();
        $order = $orderStatement->get_result()->fetch_assoc();
        $orderStatement->close();
        if (!$order) {
            throw new DomainException('The order no longer exists.');
        }
        if (in_array($order['payment_status'], ['refund_pending', 'refunded'], true)
            && $newStatus !== 'cancelled'
        ) {
            throw new DomainException('A refunded order must remain cancelled.');
        }

        $inventoryDeducted = (bool) $order['inventory_deducted'];
        $statusChanged = (string) $order['status'] !== $newStatus;
        $items = normalizedOrderItems((string) $order['order_details']);

        if (in_array($newStatus, $stockStatuses, true) && !$inventoryDeducted) {
            foreach ($items as $item) {
                $productId = (string) ($item['productId'] ?? '');
                $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
                if ($productId === '' || $quantity === false || $quantity < 1) {
                    continue; // Legacy orders did not store product IDs.
                }

                $productStatement = $database->prepare(
                    'SELECT name, track_stock, stock_quantity FROM products WHERE id = ? FOR UPDATE'
                );
                $productStatement->bind_param('s', $productId);
                $productStatement->execute();
                $product = $productStatement->get_result()->fetch_assoc();
                $productStatement->close();
                if (!$product) {
                    throw new DomainException("Product {$productId} no longer exists.");
                }
                if ((bool) $product['track_stock']) {
                    if ((int) $product['stock_quantity'] < $quantity) {
                        throw new DomainException(
                            'Not enough stock for ' . $product['name'] . '. Available: ' . (int) $product['stock_quantity'] . '.'
                        );
                    }
                    $stockStatement = $database->prepare(
                        'UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?'
                    );
                    $stockStatement->bind_param('is', $quantity, $productId);
                    $stockStatement->execute();
                    $stockStatement->close();
                }
            }
            $inventoryDeducted = true;
        } elseif ($newStatus === 'cancelled' && $inventoryDeducted) {
            foreach ($items as $item) {
                $productId = (string) ($item['productId'] ?? '');
                $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
                if ($productId === '' || $quantity === false || $quantity < 1) {
                    continue;
                }
                $stockStatement = $database->prepare(
                    'UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ? AND track_stock = 1'
                );
                $stockStatement->bind_param('is', $quantity, $productId);
                $stockStatement->execute();
                $stockStatement->close();
            }
            $inventoryDeducted = false;
        }

        $deductedValue = $inventoryDeducted ? 1 : 0;
        $updateStatement = $database->prepare(
            'UPDATE orders SET status = ?, inventory_deducted = ? WHERE id = ?'
        );
        $updateStatement->bind_param('sii', $newStatus, $deductedValue, $orderId);
        $updateStatement->execute();
        $updateStatement->close();

        if ($statusChanged) {
            $historyStatement = $database->prepare(
                "INSERT INTO order_status_history (order_id, status, source) VALUES (?, ?, 'admin')"
            );
            $historyStatement->bind_param('is', $orderId, $newStatus);
            $historyStatement->execute();
            $historyStatement->close();
        }
        $database->commit();
        return $statusChanged;
    } catch (Throwable $error) {
        $database->rollback();
        throw $error;
    }
}
