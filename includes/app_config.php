<?php
declare(strict_types=1);

/**
 * Read application configuration. Server environment variables take precedence,
 * while config.php provides a convenient local-development fallback.
 */
function appConfig(string $environmentName, mixed $default = ''): mixed
{
    $environmentValue = getenv($environmentName);
    if ($environmentValue !== false && $environmentValue !== '') {
        return $environmentValue;
    }

    static $configuration;
    if (!is_array($configuration)) {
        $path = dirname(__DIR__) . '/config.php';
        $loaded = is_file($path) ? require $path : [];
        $configuration = is_array($loaded) ? $loaded : [];
    }

    $paths = [
        'F2H_DB_HOST' => ['db', 'host'],
        'F2H_DB_NAME' => ['db', 'name'],
        'F2H_DB_USER' => ['db', 'user'],
        'F2H_DB_PASSWORD' => ['db', 'password'],
        'F2H_ADMIN_USERNAME' => ['admin', 'username'],
        'F2H_ADMIN_PASSWORD_HASH' => ['admin', 'password_hash'],
        'F2H_RAZORPAY_KEY_ID' => ['razorpay', 'key_id'],
        'F2H_RAZORPAY_KEY_SECRET' => ['razorpay', 'key_secret'],
        'F2H_RAZORPAY_WEBHOOK_SECRET' => ['razorpay', 'webhook_secret'],
        'F2H_TELEGRAM_BOT_TOKEN' => ['telegram', 'bot_token'],
        'F2H_TELEGRAM_CHAT_ID' => ['telegram', 'chat_id'],
    ];
    if (!isset($paths[$environmentName])) {
        return $default;
    }

    $value = $configuration;
    foreach ($paths[$environmentName] as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return $default;
        }
        $value = $value[$key];
    }
    return $value === null ? $default : $value;
}
