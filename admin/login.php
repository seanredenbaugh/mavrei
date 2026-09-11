<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
if (is_admin()) { header('Location: ' . base_url('admin/')); exit; }
$hasUsers = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
if (!$hasUsers) { header('Location: ' . base_url('admin/setup.php')); exit; }
$error = !empty($_SESSION['admin_expired']) ? 'Your admin session expired. Please sign in again.' : '';
unset($_SESSION['admin_expired']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    try {
        if (login_is_rate_limited($email)) {
            $error = 'Too many sign-in attempts. Please wait 15 minutes and try again.';
        } else {
            $stmt = db()->prepare('SELECT id, name, password_hash FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            $verified = $user
                ? password_verify($password, (string) $user['password_hash'])
                : password_verify($password, password_hash('unused-login-value', PASSWORD_DEFAULT));
            if ($user && $verified) {
                clear_failed_logins($email);
                if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
                    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                }
                establish_admin_session($user);
                db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
                $target = safe_local_target((string) ($_SESSION['after_login'] ?? ''), base_url('admin/'));
                unset($_SESSION['after_login']);
                header('Location: ' . $target);
                exit;
            }
            record_failed_login($email);
            $error = 'The email or password was not recognized.';
        }
    } catch (Throwable $exception) {
        error_log('Admin login failed: ' . $exception->getMessage());
        $error = 'Sign-in is temporarily unavailable. Please try again shortly.';
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin login</title><link rel="stylesheet" href="<?= e(base_url('assets/css/admin.css?v=20260911-2')) ?>"></head><body class="login-page"><main class="login-card"><a class="logo-home" href="<?= e(base_url()) ?>"><img src="<?= e(base_url('assets/img/mavrei-logo-transparent.png?v=20260910-3')) ?>" alt="Maverick"></a><h1>Portfolio admin</h1><?php if ($error): ?><p class="alert error"><?= e($error) ?></p><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Email<input type="email" name="email" required autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button type="submit">Sign in</button></form><a href="<?= e(base_url()) ?>">← Return to map</a></main></body></html>
