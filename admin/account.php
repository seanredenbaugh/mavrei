<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require_admin();

$userId = (int) ($_SESSION['admin_user_id'] ?? 0);
$stmt = db()->prepare('SELECT id, name, email, password_hash FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) {
    clear_admin_auth(true);
    header('Location: ' . base_url('admin/login.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    try {
        if (!password_verify($currentPassword, (string) $user['password_hash'])) {
            throw new RuntimeException('The current password is incorrect.');
        }
        if (strlen($newPassword) < 12) {
            throw new RuntimeException('The new password must contain at least 12 characters.');
        }
        if (strlen($newPassword) > 72) {
            throw new RuntimeException('The new password cannot exceed 72 characters.');
        }
        if ($newPassword !== $confirmPassword) {
            throw new RuntimeException('The new passwords do not match.');
        }
        if (password_verify($newPassword, (string) $user['password_hash'])) {
            throw new RuntimeException('Choose a password different from the current password.');
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!is_string($newHash) || $newHash === '') throw new RuntimeException('The new password could not be secured.');
        $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$newHash, $userId]);
        try {
            clear_failed_logins((string) $user['email']);
        } catch (Throwable $cleanupException) {
            error_log('Failed-login cleanup after password change failed: ' . $cleanupException->getMessage());
        }
        establish_admin_session($user);
        header('Location: ' . base_url('admin/account.php?changed=1'));
        exit;
    } catch (Throwable $exception) {
        error_log('Admin password change failed: ' . $exception->getMessage());
        $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'The password could not be changed. Please try again.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Account | Admin</title>
  <link rel="stylesheet" href="<?= e(base_url('assets/css/admin.css?v=20260911-2')) ?>">
</head>
<body>
<header class="admin-header">
  <a class="logo-home" href="<?= e(base_url()) ?>"><img src="<?= e(base_url('assets/img/mavrei-logo-transparent.png?v=20260910-3')) ?>" alt="Maverick"></a>
  <nav><a href="<?= e(base_url('admin/')) ?>">Properties</a><a href="<?= e(base_url('admin/map-settings.php')) ?>">Map settings</a><a href="<?= e(base_url()) ?>">View map</a></nav>
</header>
<main class="admin-wrap narrow">
  <div class="admin-title"><div><p class="eyebrow">Portfolio administration</p><h1>Account</h1></div></div>
  <?php if (isset($_GET['changed'])): ?><p class="alert success">Your password has been changed.</p><?php endif; ?>
  <?php if ($error): ?><p class="alert error"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="property-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <section>
      <h2>Change password</h2>
      <p>Signed in as <strong><?= e($user['email']) ?></strong>. Enter your current password before choosing a new one.</p>
      <div class="form-grid">
        <label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
        <label>New password<input type="password" name="new_password" minlength="12" maxlength="72" required autocomplete="new-password"></label>
        <label>Confirm new password<input type="password" name="confirm_password" minlength="12" maxlength="72" required autocomplete="new-password"></label>
      </div>
    </section>
    <button class="button" type="submit">Change password</button>
  </form>
</main>
</body>
</html>
