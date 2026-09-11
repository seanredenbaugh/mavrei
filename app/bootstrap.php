<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

const ADMIN_IDLE_TIMEOUT = 1800;
const ADMIN_ABSOLUTE_TIMEOUT = 28800;
const ADMIN_USER_RECHECK_INTERVAL = 300;
const LOGIN_WINDOW_MINUTES = 15;
const LOGIN_MAX_COMBINED_ATTEMPTS = 5;
const LOGIN_MAX_IP_ATTEMPTS = 20;

$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('Site configuration is not installed. Copy config.example.php to config.php and enter the database settings.');
}

$config = require $configFile;
date_default_timezone_set($config['app']['timezone'] ?? 'America/Chicago');

if (!($config['app']['debug'] ?? false)) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0])) === 'https';
}

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com; style-src 'self' 'unsafe-inline' https://unpkg.com; img-src 'self' data: blob: https://tiles.openfreemap.org; connect-src 'self' https://tiles.openfreemap.org; worker-src 'self' blob:; font-src 'self' data:; upgrade-insecure-requests");
    if (request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

send_security_headers();

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('mavrei_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => true,
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

function clear_admin_auth(bool $expired = false): void
{
    unset(
        $_SESSION['admin_user_id'],
        $_SESSION['admin_name'],
        $_SESSION['admin_started_at'],
        $_SESSION['admin_last_activity'],
        $_SESSION['admin_last_checked']
    );
    unset($_SESSION['csrf_token']);
    if ($expired) {
        $_SESSION['admin_expired'] = true;
    }
    session_regenerate_id(true);
}

function establish_admin_session(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['admin_user_id'] = (int) $user['id'];
    $_SESSION['admin_name'] = (string) $user['name'];
    $_SESSION['admin_started_at'] = time();
    $_SESSION['admin_last_activity'] = time();
    $_SESSION['admin_last_checked'] = time();
    unset($_SESSION['admin_expired'], $_SESSION['csrf_token']);
}

function is_admin(): bool
{
    $userId = filter_var($_SESSION['admin_user_id'] ?? null, FILTER_VALIDATE_INT);
    $startedAt = filter_var($_SESSION['admin_started_at'] ?? null, FILTER_VALIDATE_INT);
    $lastActivity = filter_var($_SESSION['admin_last_activity'] ?? null, FILTER_VALIDATE_INT);
    if (!$userId || !$startedAt || !$lastActivity) {
        if (isset($_SESSION['admin_user_id'])) {
            clear_admin_auth(true);
        }
        return false;
    }

    $now = time();
    if (($now - $lastActivity) > ADMIN_IDLE_TIMEOUT || ($now - $startedAt) > ADMIN_ABSOLUTE_TIMEOUT) {
        clear_admin_auth(true);
        return false;
    }

    $lastChecked = (int) ($_SESSION['admin_last_checked'] ?? 0);
    if (($now - $lastChecked) >= ADMIN_USER_RECHECK_INTERVAL) {
        try {
            $stmt = db()->prepare('SELECT id, name FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if (!$user) {
                clear_admin_auth(true);
                return false;
            }
            $_SESSION['admin_name'] = (string) $user['name'];
            $_SESSION['admin_last_checked'] = $now;
        } catch (Throwable $exception) {
            error_log('Admin session validation failed: ' . $exception->getMessage());
            clear_admin_auth(true);
            return false;
        }
    }

    $_SESSION['admin_last_activity'] = $now;
    return true;
}

function require_admin(): void
{
    if (!is_admin()) {
        $requested = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $_SESSION['after_login'] = safe_local_target($requested, base_url('admin/'));
        header('Location: ' . base_url('admin/login.php'));
        exit;
    }
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
    ensure_property_schema();
}

function safe_local_target(string $target, string $fallback): string
{
    if ($target === '' || !str_starts_with($target, '/') || str_starts_with($target, '//') || preg_match('/[\r\n]/', $target)) {
        return $fallback;
    }
    return $target;
}

function client_ip_hash(): string
{
    return hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function email_login_hash(string $email): string
{
    return hash('sha256', strtolower(trim($email)));
}

function ensure_security_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    db()->exec("CREATE TABLE IF NOT EXISTS admin_login_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ip_hash CHAR(64) NOT NULL,
        email_hash CHAR(64) NOT NULL,
        attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_login_ip_time (ip_hash, attempted_at),
        INDEX idx_login_email_time (email_hash, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $checked = true;
}

function login_is_rate_limited(string $email): bool
{
    ensure_security_schema();
    $ipHash = client_ip_hash();
    $emailHash = email_login_hash($email);
    $stmt = db()->prepare("SELECT
        SUM(ip_hash = ?) AS ip_attempts,
        SUM(ip_hash = ? AND email_hash = ?) AS combined_attempts
        FROM admin_login_attempts
        WHERE attempted_at >= DATE_SUB(NOW(), INTERVAL " . LOGIN_WINDOW_MINUTES . " MINUTE)");
    $stmt->execute([$ipHash, $ipHash, $emailHash]);
    $counts = $stmt->fetch() ?: [];
    return (int) ($counts['ip_attempts'] ?? 0) >= LOGIN_MAX_IP_ATTEMPTS
        || (int) ($counts['combined_attempts'] ?? 0) >= LOGIN_MAX_COMBINED_ATTEMPTS;
}

function record_failed_login(string $email): void
{
    ensure_security_schema();
    $stmt = db()->prepare('INSERT INTO admin_login_attempts (ip_hash, email_hash) VALUES (?, ?)');
    $stmt->execute([client_ip_hash(), email_login_hash($email)]);
    if (random_int(1, 20) === 1) {
        db()->exec('DELETE FROM admin_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    }
}

function clear_failed_logins(string $email): void
{
    ensure_security_schema();
    $stmt = db()->prepare('DELETE FROM admin_login_attempts WHERE ip_hash = ? AND email_hash = ?');
    $stmt->execute([client_ip_hash(), email_login_hash($email)]);
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
