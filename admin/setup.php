<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if ((int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) { header('Location: ' . base_url('admin/login.php')); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string)($_POST['name'] ?? '')); $email = strtolower(trim((string)($_POST['email'] ?? ''))); $password = (string)($_POST['password'] ?? '');
    if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) $error = 'Enter your name, a valid email, and a password of at least 12 characters.';
    else {
        $stmt=db()->prepare('INSERT INTO users (name,email,password_hash) VALUES (?,?,?)'); $stmt->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
        session_regenerate_id(true); $_SESSION['admin_user_id']=(int)db()->lastInsertId(); $_SESSION['admin_name']=$name;
        header('Location: ' . base_url('admin/')); exit;
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Create administrator</title><link rel="stylesheet" href="<?= e(base_url('assets/css/admin.css')) ?>"></head><body class="login-page"><main class="login-card"><img src="<?= e(base_url('assets/img/mavreilogo.png')) ?>" alt="Maverick"><h1>Create administrator</h1><p>This page locks automatically after the first account is created.</p><?php if($error):?><p class="alert error"><?=e($error)?></p><?php endif;?><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><label>Name<input name="name" required></label><label>Email<input type="email" name="email" required></label><label>Password<input type="password" name="password" minlength="12" required></label><button>Create account</button></form></main></body></html>

