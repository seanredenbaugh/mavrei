<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('Site configuration is not installed. Copy config.example.php to config.php and enter the database settings.');
}

$config = require $configFile;
date_default_timezone_set($config['app']['timezone'] ?? 'America/Chicago');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('mavrei_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function config(string $key, mixed $default = null): mixed
{
    global $config;
    $value = $config;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        config('database.host'),
        config('database.port', 3306),
        config('database.name'),
        config('database.charset', 'utf8mb4')
    );

    $pdo = new PDO($dsn, (string) config('database.username'), (string) config('database.password'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(string $path = ''): string
{
    $base = rtrim((string) config('app.base_path', ''), '/');
    return $base . '/' . ltrim($path, '/');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Your session expired. Go back, refresh the page, and try again.');
    }
}

function is_admin(): bool
{
    return isset($_SESSION['admin_user_id']);
}

function require_admin(): void
{
    if (!is_admin()) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? base_url('admin/');
        header('Location: ' . base_url('admin/login.php'));
        exit;
    }
    ensure_property_schema();
}

function ensure_property_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    foreach (['property_type', 'status'] as $column) {
        $stmt = db()->query("SHOW COLUMNS FROM properties LIKE " . db()->quote($column));
        $definition = $stmt->fetch();
        if ($definition && str_starts_with(strtolower((string) $definition['Type']), 'enum(')) {
            $default = $column === 'property_type' ? 'house' : 'rented';
            db()->exec("ALTER TABLE properties MODIFY {$column} VARCHAR(30) NOT NULL DEFAULT " . db()->quote($default));
        }
    }
}

function site_setting(string $key, string $default = ''): string
{
    try {
        $stmt = db()->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return is_string($value) && $value !== '' ? $value : $default;
    } catch (PDOException) {
        return $default;
    }
}

function status_label(string $status): string
{
    return match ($status) {
        'coming_soon' => 'Coming Soon',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

function type_label(string $type): string
{
    return match ($type) {
        'airbnb' => 'Airbnb',
        'apartment' => 'Apartment',
        default => ucwords(str_replace('_', ' ', $type)),
    };
}
