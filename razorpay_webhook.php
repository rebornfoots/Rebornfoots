<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/app_config.php';

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

$secret = (string) appConfig('F2H_RAZORPAY_WEBHOOK_SECRET');
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
$paymentEvents = ['payment.captured', 'payment.failed'];
$refundEvents = ['refund.created', 'refund.processed', 'refund.failed'];
if (!in_array($eventType, [...$paymentEvents, ...$refundEvents], true)) {
    webhookResponse(200, ['status' => 'ignored']);
}

$payment = null;
$refund = null;
$refundId = '';
$gatewayOrderId = '';
$paymentId = '';
if (in_array($eventType, $paymentEvents, true)) {
    $payment = $event['payload']['payment']['entity'] ?? null;
    if (!is_array($payment)) {
        webhookResponse(400, ['status' => 'error']);
    }
    $gatewayOrderId = (string) ($payment['order_id'] ?? '');
    $paymentId = (string) ($payment['id'] ?? '');
    if (!preg_match('/^order_[A-Za-z0-9]+$/', $gatewayOrderId)
        || !preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId)
    ) {
        webhookResponse(400, ['status' => 'error']);
    }
} else {
    $refund = $event['payload']['refund']['entity'] ?? null;
    if (!is_array($refund)) {
        webhookResponse(400, ['status' => 'error']);
    }
    $refundId = (string) ($refund['id'] ?? '');
    $paymentId = (string) ($refund['payment_id'] ?? '');
    if (!preg_match('/^rfnd_[A-Za-z0-9]+$/', $refundId)
        || !preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId)
    ) {
        webhookResponse(400, ['status' => 'error']);
    }
}

$host = (string) appConfig('F2H_DB_HOST');
$name = (string) appConfig('F2H_DB_NAME');
$user = (string) appConfig('F2H_DB_USER');
$password = (string) appConfig('F2H_DB_PASSWORD');
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

    if (in_array($eventType, $refundEvents, true)) {
        $refundStatement = $database->prepare(
            'SELECT payment_refunds.order_id, payment_refunds.amount_paise,
                    payment_refunds.gateway_refund_id, payment_refunds.status
             FROM payment_refunds
             WHERE payment_refunds.gateway_payment_id = ?
                OR payment_refunds.gateway_refund_id = ?
             LIMIT 1 FOR UPDATE'
        );
        $refundStatement->bind_param('ss', $paymentId, $refundId);
        $refundStatement->execute();
        $storedRefund = $refundStatement->get_result()->fetch_assoc();
        $refundStatement->close();
        if (!$storedRefund
            || (int) ($refund['amount'] ?? 0) !== (int) $storedRefund['amount_paise']
            || ($refund['currency'] ?? '') !== 'INR'
        ) {
            throw new DomainException('Refund data does not match a stored refund.');
        }
        if ($storedRefund['gateway_refund_id'] !== null
            && !hash_equals((string) $storedRefund['gateway_refund_id'], $refundId)
        ) {
            throw new DomainException('Refund identifier does not match the stored refund.');
        }

        $gatewayStatus = (string) ($refund['status'] ?? '');
        $expectedStatus = match ($eventType) {
            'refund.processed' => 'processed',
            'refund.failed' => 'failed',
            default => in_array($gatewayStatus, ['pending', 'processed', 'failed'], true)
                ? $gatewayStatus
                : 'pending',
        };
        if (($eventType === 'refund.processed' && $gatewayStatus !== 'processed')
            || ($eventType === 'refund.failed' && $gatewayStatus !== 'failed')
        ) {
            throw new DomainException('Refund webhook status is inconsistent.');
        }
        if (in_array($storedRefund['status'], ['processed', 'failed'], true)
            && $expectedStatus !== $storedRefund['status']
        ) {
            $database->commit();
            webhookResponse(200, ['status' => 'ignored_terminal']);
        }

        $failureReason = $expectedStatus === 'failed'
            ? mb_substr((string) ($refund['error_description'] ?? 'Razorpay could not process the refund.'), 0, 500)
            : '';
        $updateRefund = $database->prepare(
            'UPDATE payment_refunds
             SET gateway_refund_id = ?, status = ?, failure_reason = ? WHERE order_id = ?'
        );
        $refundOrderId = (int) $storedRefund['order_id'];
        $updateRefund->bind_param('sssi', $refundId, $expectedStatus, $failureReason, $refundOrderId);
        $updateRefund->execute();
        $updateRefund->close();

        $paymentStatus = match ($expectedStatus) {
            'processed' => 'refunded',
            'failed' => 'paid',
            default => 'refund_pending',
        };
        $updateOrder = $database->prepare(
            'UPDATE orders SET payment_status = ? WHERE id = ? AND gateway_payment_id = ?'
        );
        $updateOrder->bind_param('sis', $paymentStatus, $refundOrderId, $paymentId);
        $updateOrder->execute();
        if ($updateOrder->affected_rows === 0) {
            $verifyOrder = $database->prepare(
                'SELECT id FROM orders WHERE id = ? AND gateway_payment_id = ? AND payment_status = ?'
            );
            $verifyOrder->bind_param('iss', $refundOrderId, $paymentId, $paymentStatus);
            $verifyOrder->execute();
            $validOrder = $verifyOrder->get_result()->fetch_assoc();
            $verifyOrder->close();
            if (!$validOrder) {
                $updateOrder->close();
                throw new DomainException('Refund payment does not match its order.');
            }
        }
        $updateOrder->close();
        $database->commit();
        webhookResponse(200, ['status' => 'processed']);
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
        if (in_array($order['payment_status'], ['created', 'failed'], true)) {
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
        if (!in_array($order['payment_status'], ['refund_pending', 'refunded'], true)) {
            $paid = 'paid';
            $update = $database->prepare(
                'UPDATE orders SET gateway_payment_id = ?, payment_status = ? WHERE id = ?'
            );
            $update->bind_param('ssi', $paymentId, $paid, $orderId);
            $update->execute();
            $update->close();
        }
        $database->commit();
            error_log("InbornFood: captured payment {$paymentId} needs refund for cancelled order #{$orderId}.");
        webhookResponse(200, ['status' => 'refund_required']);
    }

    if (!in_array($order['payment_status'], ['paid', 'refund_pending', 'refunded'], true)) {
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
        error_log('InbornFood Razorpay webhook review: ' . $error->getMessage());
    webhookResponse(409, ['status' => 'review_required']);
} catch (Throwable $error) {
    if ($database instanceof mysqli) {
        try { $database->rollback(); } catch (Throwable) {}
    }
    error_log('InbornFood Razorpay webhook error: ' . $error->getMessage());
    webhookResponse(500, ['status' => 'error']);
}
