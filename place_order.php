<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

const MAX_REQUEST_BYTES = 16384;
const MAX_ITEM_QUANTITY = 20;

// Prices live only on the server. Never trust names or prices sent by a browser.
const PRODUCTS = [
    'karupatti-500' => [
        'name' => 'Pure Palm Jaggery',
        'variant' => '500 g',
        'price' => 420.00,
    ],
    'cow-butter-1kg' => [
        'name' => 'Pure Cow Butter',
        'variant' => '1 kg (2 x 500 g)',
        'price' => 820.00,
    ],
    'buffalo-butter-1kg' => [
        'name' => 'Fresh White Butter',
        'variant' => '1 kg (2 x 500 g)',
        'price' => 840.00,
    ],
    'cow-ghee-1l' => [
        'name' => 'Traditional Cow Ghee',
        'variant' => '1 litre (2 x 500 ml)',
        'price' => 1020.00,
    ],
    'buffalo-ghee-1l' => [
        'name' => 'Village-Style Ghee',
        'variant' => '1 litre (2 x 500 ml)',
        'price' => 1050.00,
    ],
];

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
        curl_exec($curl);
        curl_close($curl);
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
    @file_get_contents($url, false, $context);
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
$phone = preg_replace('/\D/', '', (string) ($request['phone'] ?? '')) ?? '';
$address = cleanText($request['address'] ?? '');
$payment = (string) ($request['payment'] ?? '');
$items = $request['items'] ?? null;

$errors = [];
if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
    $errors[] = 'Enter a valid full name.';
}
if (!preg_match('/^[6-9]\d{9}$/', $phone)) {
    $errors[] = 'Enter a valid 10-digit Indian mobile number.';
}
if (mb_strlen($address) < 10 || mb_strlen($address) > 500) {
    $errors[] = 'Enter a complete delivery address.';
}
if ($payment !== 'UPI') {
    $errors[] = 'Select a supported payment method.';
}
if (!is_array($items) || count($items) < 1 || count($items) > count(PRODUCTS)) {
    $errors[] = 'Your cart is empty or invalid.';
}
if ($errors !== []) {
    respond(422, ['status' => 'error', 'message' => implode(' ', $errors)]);
}

$validatedItems = [];
$total = 0.0;
foreach ($items as $item) {
    if (!is_array($item)) {
        respond(422, ['status' => 'error', 'message' => 'Your cart contains an invalid item.']);
    }

    $productId = (string) ($item['productId'] ?? '');
    $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
    if (!isset(PRODUCTS[$productId]) || $quantity === false || $quantity < 1 || $quantity > MAX_ITEM_QUANTITY) {
        respond(422, ['status' => 'error', 'message' => 'Your cart contains an unavailable item or quantity.']);
    }
    if (isset($validatedItems[$productId])) {
        respond(422, ['status' => 'error', 'message' => 'Your cart contains duplicate items.']);
    }

    $product = PRODUCTS[$productId];
    $subtotal = $product['price'] * $quantity;
    $validatedItems[$productId] = [
        'name' => $product['name'],
        'variant' => $product['variant'],
        'price' => $product['price'],
        'quantity' => $quantity,
        'subtotal' => $subtotal,
    ];
    $total += $subtotal;
}

$dbHost = getenv('F2H_DB_HOST') ?: '';
$dbName = getenv('F2H_DB_NAME') ?: '';
$dbUser = getenv('F2H_DB_USER') ?: '';
$dbPassword = getenv('F2H_DB_PASSWORD') ?: '';

if ($dbHost === '' || $dbName === '' || $dbUser === '') {
    error_log('InbornFoot: database environment variables are not configured.');
    respond(503, ['status' => 'error', 'message' => 'Ordering is temporarily unavailable. Please contact us.']);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $database = new mysqli($dbHost, $dbUser, $dbPassword, $dbName);
    $database->set_charset('utf8mb4');

    $orderDetails = json_encode(array_values($validatedItems), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $statement = $database->prepare(
        'INSERT INTO orders (customer_name, phone, address, payment_method, order_details, total)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $statement->bind_param('sssssd', $name, $phone, $address, $payment, $orderDetails, $total);
    $statement->execute();
    $orderId = $database->insert_id;
    $statement->close();
    $database->close();
} catch (Throwable $error) {
    error_log('InbornFoot order error: ' . $error->getMessage());
    respond(500, ['status' => 'error', 'message' => 'We could not save your order. Please try again.']);
}

$lines = [
    '🧺 <b>InbornFoot order</b>',
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
$lines[] = '💰 <b>Total: ₹' . number_format($total, 0) . '</b>';
$lines[] = '💳 Payment: UPI after verification';

sendTelegram(
    implode("\n", $lines),
    getenv('F2H_TELEGRAM_BOT_TOKEN') ?: '',
    getenv('F2H_TELEGRAM_CHAT_ID') ?: ''
);

respond(201, [
    'status' => 'success',
    'orderId' => $orderId,
    'total' => $total,
]);
