<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (is_admin()) { header('Location: ' . base_url('admin/')); exit; }
$hasUsers = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
if (!$hasUsers) { header('Location: ' . base_url('admin/setup.php')); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $stmt = db()->prepare('SELECT id, name, password_hash FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([strtolower(trim((string)($_POST['email'] ?? '')))]);
    $user = $stmt->fetch();
    if ($user && password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_user_id'] = (int)$user['id'];
        $_SESSION['admin_name'] = $user['name'];
        db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
        $target = $_SESSION['after_login'] ?? base_url('admin/'); unset($_SESSION['after_login']);
        header('Location: ' . $target); exit;
    }
    $error = 'The email or password was not recognized.';
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin login</title><link rel="stylesheet" href="<?= e(base_url('assets/css/admin.css')) ?>"></head><body class="login-page"><main class="login-card"><img src="<?= e(base_url('assets/img/mavreilogo.png')) ?>" alt="Maverick"><h1>Portfolio admin</h1><?php if ($error): ?><p class="alert error"><?= e($error) ?></p><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Email<input type="email" name="email" required autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button type="submit">Sign in</button></form><a href="<?= e(base_url()) ?>">← Return to map</a></main></body></html>

