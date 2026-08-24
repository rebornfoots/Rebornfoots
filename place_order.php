<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/app_config.php';
require_once __DIR__ . '/includes/delivery.php';

require __DIR__ . '/includes/pricing.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

const MAX_REQUEST_BYTES = 16384;
const MAX_ITEM_QUANTITY = 20;

function respond(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cleanText(mixed $value): string
{
    return trim(preg_replace('/\s+/u', ' ', is_string($value) ? $value : '') ?? '');
}

function telegramEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sendTelegram(string $message, string $botToken, string $chatId): void
{
    if ($botToken === '' || $chatId === '') {
        return;
    }

    $url = 'https://api.telegram.org/bot' . rawurlencode($botToken) . '/sendMessage';
    $body = http_build_query([
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML',
    ]);

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);
        if ($response === false || $status < 200 || $status >= 300) {
            error_log('InbornFood Telegram notification failed: ' . ($curlError !== '' ? $curlError : "HTTP {$status}"));
        }
        return;
    }

    $context = stream_context_create([
        'http' => [
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => $body,
            'timeout' => 5,
        ],
    ]);
    if (@file_get_contents($url, false, $context) === false) {
        error_log('InbornFood Telegram notification failed using the HTTP fallback.');
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength <= 0 || $contentLength > MAX_REQUEST_BYTES) {
    respond(413, ['status' => 'error', 'message' => 'The order request is empty or too large.']);
}

$rawBody = file_get_contents('php://input', false, null, 0, MAX_REQUEST_BYTES + 1);
try {
    $request = json_decode($rawBody ?: '', true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    respond(400, ['status' => 'error', 'message' => 'Invalid order data.']);
}

if (!is_array($request)) {
    respond(400, ['status' => 'error', 'message' => 'Invalid order data.']);
}

$name = cleanText($request['name'] ?? '');
$requestId = strtolower((string) ($request['requestId'] ?? ''));
$phone = preg_replace('/\D/', '', (string) ($request['phone'] ?? '')) ?? '';
$address = cleanText($request['address'] ?? '');
$pincode = preg_replace('/\D/', '', (string) ($request['pincode'] ?? '')) ?? '';
$payment = (string) ($request['payment'] ?? '');
$couponCode = normalizedCouponCode($request['couponCode'] ?? '');
$items = $request['items'] ?? null;

$errors = [];
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $requestId)) {
    $errors[] = 'Invalid checkout request. Refresh and try again.';
}
if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
    $errors[] = 'Enter a valid full name.';
}
if (!preg_match('/^[6-9]\d{9}$/', $phone)) {
    $errors[] = 'Enter a valid 10-digit Indian mobile number.';
}
if (mb_strlen($address) < 10 || mb_strlen($address) > 500) {
    $errors[] = 'Enter a complete delivery address.';
}
if (!preg_match('/^[1-9]\d{5}$/', $pincode)) {
    $errors[] = 'Enter a valid 6-digit delivery PIN code.';
}
if ($payment !== 'UPI') {
    $errors[] = 'Select a supported payment method.';
}
if ($couponCode !== '' && !preg_match('/^[A-Z0-9_-]{3,30}$/', $couponCode)) {
    $errors[] = 'Enter a valid coupon code.';
}
if (!is_array($items) || count($items) < 1 || count($items) > 50) {
    $errors[] = 'Your cart is empty or invalid.';
}
if ($errors !== []) {
    respond(422, ['status' => 'error', 'message' => implode(' ', $errors)]);
}

$requestedItems = [];
foreach ($items as $item) {
    if (!is_array($item)) {
        respond(422, ['status' => 'error', 'message' => 'Your cart contains an invalid item.']);
    }

    $productId = (string) ($item['productId'] ?? '');
    $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
    if (!preg_match('/^[a-z0-9-]{2,64}$/', $productId)
        || $quantity === false
        || $quantity < 1
        || $quantity > MAX_ITEM_QUANTITY
    ) {
        respond(422, ['status' => 'error', 'message' => 'Your cart contains an unavailable item or quantity.']);
    }
    if (isset($requestedItems[$productId])) {
        respond(422, ['status' => 'error', 'message' => 'Your cart contains duplicate items.']);
    }
    $requestedItems[$productId] = $quantity;
}

$dbHost = (string) appConfig('F2H_DB_HOST');
$dbName = (string) appConfig('F2H_DB_NAME');
$dbUser = (string) appConfig('F2H_DB_USER');
$dbPassword = (string) appConfig('F2H_DB_PASSWORD');
if ($dbHost === '' || $dbName === '' || $dbUser === '') {
    error_log('InbornFood: database environment variables are not configured.');
    respond(503, ['status' => 'error', 'message' => 'Ordering is temporarily unavailable. Please contact us.']);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$database = null;
$transactionStarted = false;
try {
    $database = new mysqli($dbHost, $dbUser, $dbPassword, $dbName);
    $database->set_charset('utf8mb4');

    // Return the original result when a browser safely retries the same checkout.
    $existingStatement = $database->prepare(
        'SELECT id, customer_name, phone, subtotal, delivery_fee, coupon_code, coupon_discount,
                combo_discount, discount_total, total, estimated_delivery_date
         FROM orders WHERE request_key = ? LIMIT 1'
    );
    $existingStatement->bind_param('s', $requestId);
    $existingStatement->execute();
    $existingOrder = $existingStatement->get_result()->fetch_assoc();
    $existingStatement->close();
    if ($existingOrder !== null) {
        if (!hash_equals((string) $existingOrder['phone'], $phone)) {
            respond(409, ['status' => 'error', 'message' => 'This checkout request is no longer valid. Refresh and try again.']);
        }
        respond(200, [
            'status' => 'success',
            'orderId' => (int) $existingOrder['id'],
            'subtotal' => (float) $existingOrder['subtotal'],
            'deliveryFee' => (float) $existingOrder['delivery_fee'],
            'couponCode' => (string) $existingOrder['coupon_code'],
            'couponDiscount' => (float) $existingOrder['coupon_discount'],
            'comboDiscount' => (float) $existingOrder['combo_discount'],
            'discountTotal' => (float) $existingOrder['discount_total'],
            'total' => (float) $existingOrder['total'],
            'estimatedDeliveryDate' => $existingOrder['estimated_delivery_date'],
            'duplicate' => true,
            'customer' => ['name' => (string) $existingOrder['customer_name'], 'phone' => $phone],
        ]);
    }

    try {
        $delivery = deliveryQuoteForPincode($database, $pincode);
    } catch (DomainException $error) {
        respond(422, ['status' => 'error', 'message' => $error->getMessage()]);
    }
    $deliveryFee = $delivery['deliveryFee'];
    $minDeliveryDays = $delivery['minDays'];
    $maxDeliveryDays = $delivery['maxDays'];

    // Product identity, availability and prices always come from MySQL.
    $productIds = array_keys($requestedItems);
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $catalogStatement = $database->prepare(
        "SELECT id, name, variant, price, track_stock, stock_quantity
         FROM products
         WHERE id IN ({$placeholders}) AND active = 1 AND purchasable = 1 AND price IS NOT NULL"
    );
    $catalogTypes = str_repeat('s', count($productIds));
    $catalogStatement->bind_param($catalogTypes, ...$productIds);
    $catalogStatement->execute();
    $catalogProducts = $catalogStatement->get_result()->fetch_all(MYSQLI_ASSOC);
    $catalogStatement->close();

    if (count($catalogProducts) !== count($requestedItems)) {
        respond(422, ['status' => 'error', 'message' => 'A product in your cart is no longer available. Refresh and try again.']);
    }

    $validatedItems = [];
    $itemsSubtotal = 0.0;
    foreach ($catalogProducts as $product) {
        $productId = (string) $product['id'];
        $quantity = $requestedItems[$productId];
        if ((bool) $product['track_stock'] && $quantity > (int) $product['stock_quantity']) {
            respond(422, [
                'status' => 'error',
                'message' => $product['name'] . ' has only ' . (int) $product['stock_quantity'] . ' item(s) available.',
            ]);
        }
        $price = (float) $product['price'];
        $subtotal = $price * $quantity;
        $validatedItems[$productId] = [
            'productId' => $productId,
            'name' => (string) $product['name'],
            'variant' => (string) $product['variant'],
            'price' => $price,
            'quantity' => $quantity,
            'subtotal' => $subtotal,
        ];
        $itemsSubtotal += $subtotal;
    }

    try {
        $promotions = calculatePromotions($database, $validatedItems, $itemsSubtotal, $couponCode);
    } catch (DomainException $promotionError) {
        respond(422, ['status' => 'error', 'message' => $promotionError->getMessage()]);
    }
    $couponCode = $promotions['couponCode'];
    $couponDiscount = (float) $promotions['couponDiscount'];
    $comboDiscount = (float) $promotions['comboDiscount'];
    $discountTotal = (float) $promotions['discountTotal'];
    $total = round(max(0, $itemsSubtotal - $discountTotal) + $deliveryFee, 2);
    $database->begin_transaction();
    $transactionStarted = true;
    $orderDetails = json_encode(array_values($validatedItems), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $estimatedDeliveryDate = deliveryDateAfterWorkingDays($maxDeliveryDays);
    $promotionsJson = json_encode([
        'coupon' => $promotions['coupon'],
        'combos' => $promotions['combos'],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $statement = $database->prepare(
        'INSERT INTO orders (request_key, customer_name, phone, address, postal_code, payment_method,
         order_details, subtotal, delivery_fee, coupon_code, coupon_discount,
         combo_discount, discount_total, promotions_json, total, estimated_delivery_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->bind_param(
        'sssssssddsdddsds',
        $requestId,
        $name,
        $phone,
        $address,
        $pincode,
        $payment,
        $orderDetails,
        $itemsSubtotal,
        $deliveryFee,
        $couponCode,
        $couponDiscount,
        $comboDiscount,
        $discountTotal,
        $promotionsJson,
        $total,
        $estimatedDeliveryDate
    );
    $statement->execute();
    $orderId = $database->insert_id;
    $statement->close();

    if ($couponCode !== '') {
        $couponUsage = $database->prepare(
            'UPDATE coupons SET used_count = used_count + 1
             WHERE code = ? AND active = 1
               AND (usage_limit IS NULL OR used_count < usage_limit)'
        );
        $couponUsage->bind_param('s', $couponCode);
        $couponUsage->execute();
        if ($couponUsage->affected_rows !== 1) {
            $couponUsage->close();
            throw new DomainException('This coupon is no longer available.');
        }
        $couponUsage->close();
    }

    $historyStatement = $database->prepare(
        "INSERT INTO order_status_history (order_id, status, source) VALUES (?, 'pending', 'system')"
    );
    $historyStatement->bind_param('i', $orderId);
    $historyStatement->execute();
    $historyStatement->close();
    $database->commit();
    $transactionStarted = false;
    $database->close();
} catch (Throwable $error) {
    if ($database instanceof mysqli && $transactionStarted) {
        try {
            $database->rollback();
            $transactionStarted = false;
        } catch (Throwable) {
            // Preserve the original failure for logging.
        }
    }
    // Two simultaneous retries can both pass the early lookup. The unique
    // request key makes one insert win; return that winning order to the loser.
    if ($database instanceof mysqli
        && $error instanceof mysqli_sql_exception
        && $error->getCode() === 1062
    ) {
        try {
            $retryStatement = $database->prepare(
                'SELECT id, customer_name, phone, subtotal, delivery_fee, coupon_code,
                        coupon_discount, combo_discount, discount_total, total,
                        estimated_delivery_date
                 FROM orders WHERE request_key = ? LIMIT 1'
            );
            $retryStatement->bind_param('s', $requestId);
            $retryStatement->execute();
            $retryOrder = $retryStatement->get_result()->fetch_assoc();
            $retryStatement->close();
            if ($retryOrder !== null && hash_equals((string) $retryOrder['phone'], $phone)) {
                respond(200, [
                    'status' => 'success',
                    'orderId' => (int) $retryOrder['id'],
                    'subtotal' => (float) $retryOrder['subtotal'],
                    'deliveryFee' => (float) $retryOrder['delivery_fee'],
                    'couponCode' => (string) $retryOrder['coupon_code'],
                    'couponDiscount' => (float) $retryOrder['coupon_discount'],
                    'comboDiscount' => (float) $retryOrder['combo_discount'],
                    'discountTotal' => (float) $retryOrder['discount_total'],
                    'total' => (float) $retryOrder['total'],
                    'estimatedDeliveryDate' => $retryOrder['estimated_delivery_date'],
                    'duplicate' => true,
                    'customer' => ['name' => (string) $retryOrder['customer_name'], 'phone' => $phone],
                ]);
            }
        } catch (Throwable $retryError) {
            error_log('InbornFood retry lookup error: ' . $retryError->getMessage());
        }
    }
    if ($error instanceof DomainException) {
        respond(409, ['status' => 'error', 'message' => $error->getMessage()]);
    }
    error_log('InbornFood order error: ' . $error->getMessage());
    respond(500, ['status' => 'error', 'message' => 'We could not save your order. Please try again.']);
}

$lines = [
    '🧺 <b>InbornFood order</b>',
    '',
    '🆔 <b>Order:</b> #' . $orderId,
    '👤 <b>Name:</b> ' . telegramEscape($name),
    '📞 <b>Phone:</b> ' . telegramEscape($phone),
    '📍 <b>Address:</b> ' . telegramEscape($address),
    '',
    '🛒 <b>Items</b>',
];
foreach ($validatedItems as $item) {
    $lines[] = sprintf(
        '• %s (%s) × %d — ₹%s',
        telegramEscape($item['name']),
        telegramEscape($item['variant']),
        $item['quantity'],
        number_format($item['subtotal'], 0)
    );
}
$lines[] = '';
$lines[] = 'Subtotal: ₹' . number_format($itemsSubtotal, 0);
$lines[] = 'Discount: -₹' . number_format($discountTotal, 0);
$lines[] = 'Delivery: ' . ($deliveryFee > 0 ? '₹' . number_format($deliveryFee, 0) : 'Free');
$lines[] = '💰 <b>Total: ₹' . number_format($total, 0) . '</b>';
$lines[] = "Estimated delivery: {$minDeliveryDays}-{$maxDeliveryDays} days";
$lines[] = '💳 Payment: UPI details to be shared after confirmation';

sendTelegram(
    implode("\n", $lines),
    (string) appConfig('F2H_TELEGRAM_BOT_TOKEN'),
    (string) appConfig('F2H_TELEGRAM_CHAT_ID')
);

respond(201, [
    'status' => 'success',
    'orderId' => $orderId,
    'subtotal' => $itemsSubtotal,
    'deliveryFee' => $deliveryFee,
    'couponCode' => $couponCode,
    'couponDiscount' => $couponDiscount,
    'comboDiscount' => $comboDiscount,
    'discountTotal' => $discountTotal,
    'combos' => $promotions['combos'],
    'total' => $total,
    'estimatedDeliveryDate' => $estimatedDeliveryDate,
    'customer' => ['name' => $name, 'phone' => $phone],
]);
