<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/app_config.php';
require_once __DIR__ . '/includes/delivery.php';

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

$host = (string) appConfig('F2H_DB_HOST');
$name = (string) appConfig('F2H_DB_NAME');
$user = (string) appConfig('F2H_DB_USER');
$password = (string) appConfig('F2H_DB_PASSWORD');
if ($host === '' || $name === '' || $user === '') {
    quoteResponse(503, ['status' => 'error', 'message' => 'Delivery quote is temporarily unavailable.']);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $database = new mysqli($host, $user, $password, $name);
    $database->set_charset('utf8mb4');
    $zone = deliveryQuoteForPincode($database, $pincode);
    quoteResponse(200, [
        'status' => 'success',
        'serviceable' => true,
        'areaName' => $zone['areaName'],
        'district' => $zone['district'],
        'state' => $zone['state'],
        'deliveryFee' => $zone['deliveryFee'],
        'minDays' => $zone['minDays'],
        'maxDays' => $zone['maxDays'],
    ]);
} catch (DomainException $error) {
    quoteResponse(422, ['status' => 'error', 'serviceable' => false, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('InbornFood delivery quote error: ' . $error->getMessage());
    quoteResponse(500, ['status' => 'error', 'message' => 'Delivery quote is temporarily unavailable.']);
}
