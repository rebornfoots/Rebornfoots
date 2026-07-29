<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function quoteResponse(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    quoteResponse(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

$pincode = preg_replace('/\D/', '', (string) ($_GET['pincode'] ?? '')) ?? '';
if (!preg_match('/^[1-9]\d{5}$/', $pincode)) {
    quoteResponse(422, ['status' => 'error', 'message' => 'Enter a valid 6-digit PIN code.']);
}

$host = getenv('F2H_DB_HOST') ?: '';
$name = getenv('F2H_DB_NAME') ?: '';
$user = getenv('F2H_DB_USER') ?: '';
$password = getenv('F2H_DB_PASSWORD') ?: '';
if ($host === '' || $name === '' || $user === '') {
    quoteResponse(503, ['status' => 'error', 'message' => 'Delivery quote is temporarily unavailable.']);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $database = new mysqli($host, $user, $password, $name);
    $database->set_charset('utf8mb4');
    $zoneCount = (int) ($database->query(
        'SELECT COUNT(*) FROM delivery_pincodes WHERE active = 1'
    )->fetch_row()[0] ?? 0);

    if ($zoneCount === 0) {
        quoteResponse(200, [
            'status' => 'success',
            'serviceable' => true,
            'areaName' => '',
            'deliveryFee' => 0,
            'minDays' => 3,
            'maxDays' => 5,
        ]);
    }

    $statement = $database->prepare(
        'SELECT area_name, delivery_fee, min_delivery_days, max_delivery_days
         FROM delivery_pincodes WHERE pincode = ? AND active = 1 LIMIT 1'
    );
    $statement->bind_param('s', $pincode);
    $statement->execute();
    $zone = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!$zone) {
        quoteResponse(422, [
            'status' => 'error',
            'serviceable' => false,
            'message' => 'Delivery is not currently available for this PIN code.',
        ]);
    }

    quoteResponse(200, [
        'status' => 'success',
        'serviceable' => true,
        'areaName' => (string) $zone['area_name'],
        'deliveryFee' => (float) $zone['delivery_fee'],
        'minDays' => (int) $zone['min_delivery_days'],
        'maxDays' => (int) $zone['max_delivery_days'],
    ]);
} catch (Throwable $error) {
    error_log('InbornFoot delivery quote error: ' . $error->getMessage());
    quoteResponse(500, ['status' => 'error', 'message' => 'Delivery quote is temporarily unavailable.']);
}
