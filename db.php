<?php
// Database connection + secure session bootstrap.
// On InfinityFree / Render set the values below (or environment variables).
$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_USER = getenv('DB_USER') ?: 'root';
$DB_PASS = getenv('DB_PASS') ?: '';
$DB_NAME = getenv('DB_NAME') ?: 'smart_campus';
$DB_PORT = (int)(getenv('DB_PORT') ?: 3306);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $ex) {
    error_log('DB connection failed: ' . $ex->getMessage());
    http_response_code(500);
    exit('Service temporarily unavailable. Please try again later.');
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Last-resort handler: log unexpected errors and show a clean message instead of a stack trace.
set_exception_handler(static function (Throwable $t): void {
    error_log('Unhandled exception: ' . $t->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo 'Something went wrong. Please try again later.';
});
