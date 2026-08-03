<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/app_config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function paymentResponse(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function fetchRazorpayPayment(string $paymentId, string $keyId, string $keySecret): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is unavailable.');
    }
    $curl = curl_init('https://api.razorpay.com/v1/payments/' . rawurlencode($paymentId));
    curl_setopt_array($curl, [
        CURLOPT_USERPWD => $keyId . ':' . $keySecret,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if (!is_string($body) || $status !== 200) {
        throw new RuntimeException('Could not verify the payment with Razorpay.');
    }
    $payment = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($payment)) {
        throw new RuntimeException('Razorpay returned an invalid payment.');
    }
    return $payment;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    paymentResponse(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

$rawBody = file_get_contents('php://input', false, null, 0, 8193);
try {
    $request = json_decode($rawBody ?: '', true, 8, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    paymentResponse(400, ['status' => 'error', 'message' => 'Invalid payment data.']);
}

$orderId = filter_var($request['orderId'] ?? null, FILTER_VALIDATE_INT);
$gatewayOrderId = (string) ($request['razorpayOrderId'] ?? '');
$paymentId = (string) ($request['razorpayPaymentId'] ?? '');
$signature = strtolower((string) ($request['razorpaySignature'] ?? ''));
if ($orderId === false || $orderId < 1
    || !preg_match('/^order_[A-Za-z0-9]+$/', $gatewayOrderId)
    || !preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId)
    || !preg_match('/^[a-f0-9]{64}$/', $signature)
) {
    paymentResponse(422, ['status' => 'error', 'message' => 'Invalid payment verification data.']);
}

$dbHost = (string) appConfig('F2H_DB_HOST');
$dbName = (string) appConfig('F2H_DB_NAME');
$dbUser = (string) appConfig('F2H_DB_USER');
$dbPassword = (string) appConfig('F2H_DB_PASSWORD');
$keyId = (string) appConfig('F2H_RAZORPAY_KEY_ID');
$keySecret = (string) appConfig('F2H_RAZORPAY_KEY_SECRET');
if ($dbHost === '' || $dbName === '' || $dbUser === '' || $keyId === '' || $keySecret === '') {
    paymentResponse(503, ['status' => 'error', 'message' => 'Payment verification is temporarily unavailable.']);
}

$expectedSignature = hash_hmac('sha256', $gatewayOrderId . '|' . $paymentId, $keySecret);
if (!hash_equals($expectedSignature, $signature)) {
    paymentResponse(400, ['status' => 'error', 'message' => 'Payment signature verification failed.']);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$database = null;
try {
    $payment = fetchRazorpayPayment($paymentId, $keyId, $keySecret);
    $database = new mysqli($dbHost, $dbUser, $dbPassword, $dbName);
    $database->set_charset('utf8mb4');
    $database->begin_transaction();

    $statement = $database->prepare(
        'SELECT gateway_order_id, gateway_payment_id, payment_status, status, total,
                order_details, inventory_deducted
         FROM orders WHERE id = ? FOR UPDATE'
    );
    $statement->bind_param('i', $orderId);
    $statement->execute();
    $order = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!$order || !hash_equals((string) $order['gateway_order_id'], $gatewayOrderId)) {
        throw new DomainException('The payment does not match this order.');
    }
    if ($order['status'] === 'cancelled') {
        throw new DomainException(
            'Payment was received for a cancelled order. Please contact us so we can arrange a refund.'
        );
    }
    if ($order['payment_status'] === 'paid') {
        if (!hash_equals((string) $order['gateway_payment_id'], $paymentId)) {
            throw new DomainException('This order is already linked to another payment.');
        }
        $database->commit();
        paymentResponse(200, ['status' => 'success', 'orderId' => $orderId, 'duplicate' => true]);
    }

    $expectedAmount = (int) round((float) $order['total'] * 100);
    if (($payment['order_id'] ?? '') !== $gatewayOrderId
        || ($payment['currency'] ?? '') !== 'INR'
        || (int) ($payment['amount'] ?? 0) !== $expectedAmount
        || ($payment['status'] ?? '') !== 'captured'
    ) {
        throw new DomainException('The payment is not captured or does not match the order total.');
    }

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
                    throw new DomainException('Payment received, but stock needs manual review. Please contact us.');
                }
                $stockStatement = $database->prepare(
                    'UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?'
                );
                $stockStatement->bind_param('is', $quantity, $productId);
                $stockStatement->execute();
                $stockStatement->close();
            }
        }
    }

    $paidStatus = 'paid';
    $update = $database->prepare(
        'UPDATE orders
         SET gateway_payment_id = ?, payment_status = ?, status = ?, inventory_deducted = 1
         WHERE id = ?'
    );
    $update->bind_param('sssi', $paymentId, $paidStatus, $paidStatus, $orderId);
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
    $database->commit();
    paymentResponse(200, ['status' => 'success', 'orderId' => $orderId]);
} catch (DomainException $error) {
    if ($database instanceof mysqli) {
        try { $database->rollback(); } catch (Throwable) {}
    }
    paymentResponse(409, ['status' => 'error', 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    if ($database instanceof mysqli) {
        try { $database->rollback(); } catch (Throwable) {}
    }
    error_log('InbornFood payment verification error: ' . $error->getMessage());
    paymentResponse(500, ['status' => 'error', 'message' => 'Payment verification failed. Please contact us with your order number.']);
}
