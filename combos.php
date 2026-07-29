<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=60, stale-while-revalidate=300');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

$host = getenv('F2H_DB_HOST') ?: '';
$name = getenv('F2H_DB_NAME') ?: '';
$user = getenv('F2H_DB_USER') ?: '';
$password = getenv('F2H_DB_PASSWORD') ?: '';
if ($host === '' || $name === '' || $user === '') {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Combos unavailable.']);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $database = new mysqli($host, $user, $password, $name);
    $database->set_charset('utf8mb4');
    $result = $database->query(
        "SELECT combo_offers.id, combo_offers.name, combo_offers.discount_type,
                combo_offers.discount_value, combo_offers.priority,
                (SELECT COUNT(*) FROM combo_offer_items configured_items
                 WHERE configured_items.combo_id = combo_offers.id) AS required_item_count,
                products.id AS product_id, products.name AS product_name,
                products.variant, products.price, products.image_url,
                products.track_stock, products.stock_quantity,
                combo_offer_items.quantity
         FROM combo_offers
         JOIN combo_offer_items ON combo_offer_items.combo_id = combo_offers.id
         JOIN products ON products.id = combo_offer_items.product_id
         WHERE combo_offers.active = 1
           AND (combo_offers.starts_at IS NULL OR combo_offers.starts_at <= NOW())
           AND (combo_offers.ends_at IS NULL OR combo_offers.ends_at >= NOW())
           AND products.active = 1 AND products.purchasable = 1 AND products.price IS NOT NULL
         ORDER BY combo_offers.priority, combo_offers.id, products.name"
    );
    $combos = [];
    while ($row = $result->fetch_assoc()) {
        $id = (int) $row['id'];
        if (!isset($combos[$id])) {
            $combos[$id] = [
                'id' => $id,
                'name' => (string) $row['name'],
                'discountType' => (string) $row['discount_type'],
                'discountValue' => (float) $row['discount_value'],
                'requiredItemCount' => (int) $row['required_item_count'],
                'items' => [],
                'originalPrice' => 0.0,
                'available' => true,
            ];
        }
        $quantity = (int) $row['quantity'];
        $price = (float) $row['price'];
        $available = !(bool) $row['track_stock'] || (int) $row['stock_quantity'] >= $quantity;
        $combos[$id]['items'][] = [
            'productId' => (string) $row['product_id'],
            'name' => (string) $row['product_name'],
            'variant' => (string) $row['variant'],
            'quantity' => $quantity,
            'imageUrl' => (string) $row['image_url'],
        ];
        $combos[$id]['originalPrice'] += $price * $quantity;
        $combos[$id]['available'] = $combos[$id]['available'] && $available;
    }
    foreach ($combos as &$combo) {
        if (count($combo['items']) < 2 || count($combo['items']) !== $combo['requiredItemCount']) {
            $combo['available'] = false;
        }
        $discount = $combo['discountType'] === 'percent'
            ? $combo['originalPrice'] * min($combo['discountValue'], 100) / 100
            : $combo['discountValue'];
        $combo['discount'] = round(min($discount, $combo['originalPrice']), 2);
        $combo['comboPrice'] = round($combo['originalPrice'] - $combo['discount'], 2);
    }
    unset($combo);
    echo json_encode(
        ['status' => 'success', 'combos' => array_values($combos)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $error) {
    error_log('InbornFoot combos API error: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Combos unavailable.']);
}
