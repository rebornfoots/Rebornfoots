<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/app_config.php';

require __DIR__ . '/includes/pricing.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function pricingResponse(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    pricingResponse(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}
$rawBody = file_get_contents('php://input', false, null, 0, 16385);
try {
    $request = json_decode($rawBody ?: '', true, 12, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    pricingResponse(400, ['status' => 'error', 'message' => 'Invalid pricing request.']);
}
$pincode = preg_replace('/\D/', '', (string) ($request['pincode'] ?? '')) ?? '';
$couponCode = normalizedCouponCode($request['couponCode'] ?? '');
$items = $request['items'] ?? null;
if (!preg_match('/^[1-9]\d{5}$/', $pincode) || !is_array($items) || count($items) < 1 || count($items) > 50) {
    pricingResponse(422, ['status' => 'error', 'message' => 'Enter a valid PIN code and cart.']);
}
$requestedItems = [];
foreach ($items as $item) {
    $productId = (string) ($item['productId'] ?? '');
    $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
    if (!preg_match('/^[a-z0-9-]{2,64}$/', $productId)
        || $quantity === false || $quantity < 1 || $quantity > 20
        || isset($requestedItems[$productId])
    ) {
        pricingResponse(422, ['status' => 'error', 'message' => 'The cart contains an invalid item.']);
    }
    $requestedItems[$productId] = $quantity;
}

$host = (string) appConfig('F2H_DB_HOST');
$name = (string) appConfig('F2H_DB_NAME');
$user = (string) appConfig('F2H_DB_USER');
$password = (string) appConfig('F2H_DB_PASSWORD');
if ($host === '' || $name === '' || $user === '') {
    pricingResponse(503, ['status' => 'error', 'message' => 'Pricing is temporarily unavailable.']);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $database = new mysqli($host, $user, $password, $name);
    $database->set_charset('utf8mb4');

    $deliveryFee = 0.0;
    $areaName = '';
    $minDays = 3;
    $maxDays = 5;
    $blockedStatement = $database->prepare(
        'SELECT reason FROM delivery_blocked_pincodes WHERE pincode = ? AND active = 1 LIMIT 1'
    );
    $blockedStatement->bind_param('s', $pincode);
    $blockedStatement->execute();
    $blocked = $blockedStatement->get_result()->fetch_assoc();
    $blockedStatement->close();
    if ($blocked) {
        pricingResponse(422, [
            'status' => 'error',
            'serviceable' => false,
            'message' => 'Delivery is not currently available for this PIN code.',
        ]);
    }
    $zoneStatement = $database->prepare(
            'SELECT area_name, delivery_fee, min_delivery_days, max_delivery_days
             FROM delivery_pincodes WHERE pincode = ? AND active = 1 LIMIT 1'
    );
    $zoneStatement->bind_param('s', $pincode);
    $zoneStatement->execute();
    $zone = $zoneStatement->get_result()->fetch_assoc();
    $zoneStatement->close();
    if ($zone) {
        $areaName = (string) $zone['area_name'];
        $deliveryFee = (float) $zone['delivery_fee'];
        $minDays = (int) $zone['min_delivery_days'];
        $maxDays = (int) $zone['max_delivery_days'];
    }

    $productIds = array_keys($requestedItems);
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $statement = $database->prepare(
        "SELECT id, name, price, track_stock, stock_quantity
         FROM products
         WHERE id IN ({$placeholders}) AND active = 1 AND purchasable = 1 AND price IS NOT NULL"
    );
    $types = str_repeat('s', count($productIds));
    $statement->bind_param($types, ...$productIds);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    if (count($rows) !== count($requestedItems)) {
        pricingResponse(422, ['status' => 'error', 'message' => 'A cart item is no longer available.']);
    }

    $pricingItems = [];
    $subtotal = 0.0;
    foreach ($rows as $product) {
        $productId = (string) $product['id'];
        $quantity = $requestedItems[$productId];
        if ((bool) $product['track_stock'] && $quantity > (int) $product['stock_quantity']) {
            pricingResponse(422, ['status' => 'error', 'message' => $product['name'] . ' does not have enough stock.']);
        }
        $price = (float) $product['price'];
        $pricingItems[$productId] = ['price' => $price, 'quantity' => $quantity];
        $subtotal += $price * $quantity;
    }
    $promotions = calculatePromotions($database, $pricingItems, $subtotal, $couponCode);
    $total = round(max(0, $subtotal - $promotions['discountTotal']) + $deliveryFee, 2);
    pricingResponse(200, [
        'status' => 'success',
        'serviceable' => true,
        'areaName' => $areaName,
        'minDays' => $minDays,
        'maxDays' => $maxDays,
        'subtotal' => $subtotal,
        'deliveryFee' => $deliveryFee,
        'couponCode' => $promotions['couponCode'],
        'couponDiscount' => $promotions['couponDiscount'],
        'comboDiscount' => $promotions['comboDiscount'],
        'discountTotal' => $promotions['discountTotal'],
        'combos' => $promotions['combos'],
        'coupon' => $promotions['coupon'],
        'total' => $total,
    ]);
} catch (DomainException $error) {
    pricingResponse(422, ['status' => 'error', 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('InbornFood pricing quote error: ' . $error->getMessage());
    pricingResponse(500, ['status' => 'error', 'message' => 'Pricing is temporarily unavailable.']);
}
