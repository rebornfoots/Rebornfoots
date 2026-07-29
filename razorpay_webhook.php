<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function webhookResponse(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    webhookResponse(405, ['status' => 'error']);
}

$secret = getenv('F2H_RAZORPAY_WEBHOOK_SECRET') ?: '';
$signature = strtolower(trim((string) ($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '')));
$eventId = trim((string) ($_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? ''));
$rawBody = file_get_contents('php://input', false, null, 0, 262145);
if ($secret === '' || !is_string($rawBody) || $rawBody === '' || strlen($rawBody) > 262144
    || !preg_match('/^[a-f0-9]{64}$/', $signature)
    || !preg_match('/^[A-Za-z0-9_-]{6,100}$/', $eventId)
) {
    webhookResponse(400, ['status' => 'error']);
}
if (!hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature)) {
    webhookResponse(400, ['status' => 'error']);
}

try {
    $event = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    webhookResponse(400, ['status' => 'error']);
}
$eventType = (string) ($event['event'] ?? '');
$payment = $event['payload']['payment']['entity'] ?? null;
if (!is_array($payment) || !in_array($eventType, ['payment.captured', 'payment.failed'], true)) {
    webhookResponse(200, ['status' => 'ignored']);
}

$gatewayOrderId = (string) ($payment['order_id'] ?? '');
$paymentId = (string) ($payment['id'] ?? '');
if (!preg_match('/^order_[A-Za-z0-9]+$/', $gatewayOrderId)
    || !preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId)
) {
    webhookResponse(400, ['status' => 'error']);
}

$host = getenv('F2H_DB_HOST') ?: '';
$name = getenv('F2H_DB_NAME') ?: '';
$user = getenv('F2H_DB_USER') ?: '';
$password = getenv('F2H_DB_PASSWORD') ?: '';
if ($host === '' || $name === '' || $user === '') {
    webhookResponse(503, ['status' => 'error']);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$database = null;
try {
    $database = new mysqli($host, $user, $password, $name);
    $database->set_charset('utf8mb4');
    $database->begin_transaction();

    $eventStatement = $database->prepare(
        'INSERT IGNORE INTO payment_webhook_events
         (event_id, event_type, gateway_order_id, gateway_payment_id) VALUES (?, ?, ?, ?)'
    );
    $eventStatement->bind_param('ssss', $eventId, $eventType, $gatewayOrderId, $paymentId);
    $eventStatement->execute();
    $newEvent = $eventStatement->affected_rows === 1;
    $eventStatement->close();
    if (!$newEvent) {
        $database->commit();
        webhookResponse(200, ['status' => 'duplicate']);
    }

    $orderStatement = $database->prepare(
        'SELECT id, status, payment_status, gateway_payment_id, total, order_details, inventory_deducted
         FROM orders WHERE gateway_order_id = ? FOR UPDATE'
    );
    $orderStatement->bind_param('s', $gatewayOrderId);
    $orderStatement->execute();
    $order = $orderStatement->get_result()->fetch_assoc();
    $orderStatement->close();
    if (!$order) {
        throw new DomainException('Gateway order was not found.');
    }
    $orderId = (int) $order['id'];

    if ($eventType === 'payment.failed') {
        if ($order['payment_status'] !== 'paid') {
            $failed = 'failed';
            $update = $database->prepare(
                'UPDATE orders SET gateway_payment_id = ?, payment_status = ? WHERE id = ?'
            );
            $update->bind_param('ssi', $paymentId, $failed, $orderId);
            $update->execute();
            $update->close();
        }
        $database->commit();
        webhookResponse(200, ['status' => 'processed']);
    }

    $expectedAmount = (int) round((float) $order['total'] * 100);
    if (($payment['status'] ?? '') !== 'captured'
        || ($payment['currency'] ?? '') !== 'INR'
        || (int) ($payment['amount'] ?? 0) !== $expectedAmount
    ) {
        throw new DomainException('Captured payment data does not match the order.');
    }

    if ($order['status'] === 'cancelled') {
        $paid = 'paid';
        $update = $database->prepare(
            'UPDATE orders SET gateway_payment_id = ?, payment_status = ? WHERE id = ?'
        );
        $update->bind_param('ssi', $paymentId, $paid, $orderId);
        $update->execute();
        $update->close();
        $database->commit();
        error_log("InbornFoot: captured payment {$paymentId} needs refund for cancelled order #{$orderId}.");
        webhookResponse(200, ['status' => 'refund_required']);
    }

    if ($order['payment_status'] !== 'paid') {
        if (!(bool) $order['inventory_deducted']) {
            $items = json_decode((string) $order['order_details'], true, 32, JSON_THROW_ON_ERROR);
            foreach ($items as $item) {
                $productId = (string) ($item['productId'] ?? '');
                $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
                if ($productId === '' || $quantity === false || $quantity < 1) {
                    continue;
                }
                $productStatement = $database->prepare(
                    'SELECT name, track_stock, stock_quantity FROM products WHERE id = ? FOR UPDATE'
                );
                $productStatement->bind_param('s', $productId);
                $productStatement->execute();
                $product = $productStatement->get_result()->fetch_assoc();
                $productStatement->close();
                if (!$product) {
                    throw new DomainException('A purchased product no longer exists.');
                }
                if ((bool) $product['track_stock']) {
                    if ((int) $product['stock_quantity'] < $quantity) {
                        throw new DomainException('Captured payment requires manual stock review.');
                    }
                    $stock = $database->prepare(
                        'UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?'
                    );
                    $stock->bind_param('is', $quantity, $productId);
                    $stock->execute();
                    $stock->close();
                }
            }
        }
        $paid = 'paid';
        $update = $database->prepare(
            'UPDATE orders SET gateway_payment_id = ?, payment_status = ?, status = ?, inventory_deducted = 1
             WHERE id = ?'
        );
        $update->bind_param('sssi', $paymentId, $paid, $paid, $orderId);
        $update->execute();
        $update->close();
        if ($order['status'] !== 'paid') {
            $history = $database->prepare(
                "INSERT INTO order_status_history (order_id, status, source) VALUES (?, 'paid', 'system')"
            );
            $history->bind_param('i', $orderId);
            $history->execute();
            $history->close();
        }
    }

    $database->commit();
    webhookResponse(200, ['status' => 'processed']);
} catch (DomainException $error) {
    if ($database instanceof mysqli) {
        try { $database->rollback(); } catch (Throwable) {}
    }
    error_log('InbornFoot Razorpay webhook review: ' . $error->getMessage());
    webhookResponse(409, ['status' => 'review_required']);
} catch (Throwable $error) {
    if ($database instanceof mysqli) {
        try { $database->rollback(); } catch (Throwable) {}
    }
    error_log('InbornFoot Razorpay webhook error: ' . $error->getMessage());
    webhookResponse(500, ['status' => 'error']);
}
