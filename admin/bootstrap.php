<?php
declare(strict_types=1);

const ADMIN_STATUSES = ['pending', 'confirmed', 'paid', 'packed', 'shipped', 'delivered', 'cancelled'];

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');

$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_name('INBORNFOOT_ADMIN');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/admin',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, private');

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function adminIsConfigured(): bool
{
    return (getenv('F2H_ADMIN_USERNAME') ?: '') !== ''
        && (getenv('F2H_ADMIN_PASSWORD_HASH') ?: '') !== '';
}

function adminIsAuthenticated(): bool
{
    return isset($_SESSION['admin_authenticated_at'], $_SESSION['admin_last_seen'])
        && (time() - (int) $_SESSION['admin_last_seen']) <= 1800;
}

function requireAdmin(): void
{
    if (!adminIsAuthenticated()) {
        $_SESSION = [];
        header('Location: /admin/');
        exit;
    }

    $_SESSION['admin_last_seen'] = time();
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function validCsrf(mixed $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals((string) $_SESSION['csrf_token'], $token);
}

function database(): mysqli
{
    static $database;
    if ($database instanceof mysqli) {
        return $database;
    }

    $host = getenv('F2H_DB_HOST') ?: '';
    $name = getenv('F2H_DB_NAME') ?: '';
    $user = getenv('F2H_DB_USER') ?: '';
    $password = getenv('F2H_DB_PASSWORD') ?: '';

    if ($host === '' || $name === '' || $user === '') {
        throw new RuntimeException('Database environment variables are not configured.');
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $database = new mysqli($host, $user, $password, $name);
    $database->set_charset('utf8mb4');
    return $database;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function takeFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function safeReturnQuery(mixed $value): string
{
    if (!is_string($value) || $value === '') {
        return '';
    }

    parse_str(ltrim($value, '?'), $parameters);
    $allowed = [];
    foreach (['q', 'status', 'page'] as $key) {
        if (isset($parameters[$key]) && is_scalar($parameters[$key])) {
            $allowed[$key] = (string) $parameters[$key];
        }
    }
    return $allowed === [] ? '' : '?' . http_build_query($allowed);
}

function normalizedOrderItems(string $details): array
{
    try {
        $items = json_decode($details, true, 32, JSON_THROW_ON_ERROR);
        if (is_array($items)) {
            return $items;
        }
    } catch (JsonException) {
        // Older records used plain text and are rendered as a single legacy item.
    }

    return [['name' => $details, 'variant' => '', 'quantity' => '', 'subtotal' => '']];
}

function statusLabel(string $status): string
{
    return ucfirst($status);
}
