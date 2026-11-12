<?php
// Session protection. Include at the top of every protected page, then call require_login().
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

const IDLE_TIMEOUT = 1800; // 30 minutes

function require_login(?string $role = null): void {
    if (empty($_SESSION['user_id'])) {
        set_flash('warning', 'Please log in to continue.');
        redirect($role === 'admin' ? 'admin_login.php' : 'login.php');
    }
    if (isset($_SESSION['last_active']) && time() - $_SESSION['last_active'] > IDLE_TIMEOUT) {
        $_SESSION = [];
        session_destroy();
        session_start();
        set_flash('warning', 'Your session expired. Please log in again.');
        redirect($role === 'admin' ? 'admin_login.php' : 'login.php');
    }
    $_SESSION['last_active'] = time();
    if ($role !== null && ($_SESSION['role'] ?? '') !== $role) {
        set_flash('danger', 'Access denied for your role.');
        redirect(($_SESSION['role'] ?? '') === 'admin' ? 'admin_dashboard.php' : 'student_dashboard.php');
    }
}
