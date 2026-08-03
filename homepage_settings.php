<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app_config.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=30, stale-while-revalidate=120');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['status' => 'error']);
    exit;
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $database = new mysqli(
        (string) appConfig('F2H_DB_HOST'),
        (string) appConfig('F2H_DB_USER'),
        (string) appConfig('F2H_DB_PASSWORD'),
        (string) appConfig('F2H_DB_NAME')
    );
    $database->set_charset('utf8mb4');
    $result = $database->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('offer_enabled','offer_text','hero_eyebrow','hero_title','hero_text','hero_image_url','hero_cta_label','hero_cta_url')");
    $settings = [];
    while ($row = $result->fetch_assoc()) {
        $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
    }
    if (($settings['offer_enabled'] ?? '0') !== '1') {
        $offer = $database->query(
            "SELECT code, description, discount_type, discount_value FROM coupons
             WHERE active = 1 AND (starts_at IS NULL OR starts_at <= NOW())
               AND (ends_at IS NULL OR ends_at >= NOW())
             ORDER BY created_at DESC LIMIT 1"
        )->fetch_assoc();
        if ($offer) {
            $saving = $offer['discount_type'] === 'percent'
                ? rtrim(rtrim((string) $offer['discount_value'], '0'), '.') . '% off'
                : '₹' . number_format((float) $offer['discount_value'], 0) . ' off';
            $settings['offer_enabled'] = '1';
            $settings['offer_text'] = trim((string) $offer['description']) !== ''
                ? (string) $offer['description'] . ' · Use code ' . $offer['code']
                : $saving . ' · Use code ' . $offer['code'];
        }
    }
    echo json_encode(['status' => 'success', 'settings' => $settings], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('InbornFood homepage settings error: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Homepage settings unavailable.']);
}
