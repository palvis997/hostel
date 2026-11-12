<?php
require_once __DIR__ . '/db.php';
$wasAdmin = ($_SESSION['role'] ?? '') === 'admin';
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
session_start();
$_SESSION['flash'][] = ['type' => 'success', 'msg' => 'You have been logged out.'];
header('Location: ' . ($wasAdmin ? 'admin_login.php' : 'login.php'));
exit;
