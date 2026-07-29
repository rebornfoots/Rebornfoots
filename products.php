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
    echo json_encode(['status' => 'error', 'message' => 'Catalogue unavailable.']);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $database = new mysqli($host, $user, $password, $name);
    $database->set_charset('utf8mb4');
    $result = $database->query(
        'SELECT id, slug, name, category, description, price, variant, badge, benefits,
                image_url, alt_text, purchasable, track_stock, stock_quantity, low_stock_threshold
         FROM products
         WHERE active = 1
         ORDER BY sort_order, name'
    );

    $products = [];
    while ($product = $result->fetch_assoc()) {
        $benefits = [];
        if (is_string($product['benefits']) && $product['benefits'] !== '') {
            try {
                $decoded = json_decode($product['benefits'], true, 16, JSON_THROW_ON_ERROR);
                $benefits = is_array($decoded) ? array_values($decoded) : [];
            } catch (JsonException) {
                $benefits = [];
            }
        }
        $inStock = !(bool) $product['track_stock'] || (int) $product['stock_quantity'] > 0;
        $products[] = [
            'id' => (string) $product['id'],
            'slug' => (string) $product['slug'],
            'name' => (string) $product['name'],
            'category' => (string) $product['category'],
            'description' => (string) $product['description'],
            'price' => $product['price'] === null ? null : (float) $product['price'],
            'variant' => (string) $product['variant'],
            'badge' => (string) $product['badge'],
            'benefits' => $benefits,
            'imageUrl' => (string) $product['image_url'],
            'altText' => (string) $product['alt_text'],
            'purchasable' => (bool) $product['purchasable'] && $inStock,
            'inStock' => $inStock,
            'trackStock' => (bool) $product['track_stock'],
            'stockQuantity' => (bool) $product['track_stock'] ? (int) $product['stock_quantity'] : null,
            'lowStock' => (bool) $product['track_stock']
                && (int) $product['stock_quantity'] > 0
                && (int) $product['stock_quantity'] <= (int) $product['low_stock_threshold'],
        ];
    }
    $database->close();

    echo json_encode(
        ['status' => 'success', 'products' => $products],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $error) {
    error_log('InbornFoot catalogue error: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Catalogue unavailable.']);
}
