<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/app_config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$checks = [
    'php' => version_compare(PHP_VERSION, '8.2.0', '>='),
    'curl' => extension_loaded('curl'),
    'mysqli' => extension_loaded('mysqli'),
    'mbstring' => extension_loaded('mbstring'),
    'database_config' => appConfig('F2H_DB_HOST') !== ''
        && appConfig('F2H_DB_NAME') !== ''
        && appConfig('F2H_DB_USER') !== '',
];

if ($checks['database_config'] && $checks['mysqli']) {
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $database = new mysqli(
            (string) appConfig('F2H_DB_HOST'),
            (string) appConfig('F2H_DB_USER'),
            (string) appConfig('F2H_DB_PASSWORD'),
            (string) appConfig('F2H_DB_NAME')
        );
        $database->set_charset('utf8mb4');
        $database->query('SELECT 1');
        $database->close();
        $checks['database_connection'] = true;
    } catch (Throwable) {
        $checks['database_connection'] = false;
    }
} else {
    $checks['database_connection'] = false;
}

$healthy = !in_array(false, $checks, true);
http_response_code($healthy ? 200 : 503);
echo json_encode([
    'status' => $healthy ? 'ok' : 'not_ready',
    'checks' => $checks,
], JSON_UNESCAPED_SLASHES);
