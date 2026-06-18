<?php
require_once __DIR__ . '/config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log('DB connection failed: ' . $conn->connect_error);
    die(json_encode(['success' => false, 'message' => 'Something went wrong. Please try again later.']));
}
$conn->set_charset('utf8mb4');

// ---------------------------------------------------------------------------
// CSRF helpers — available to any file that requires db.php
// ---------------------------------------------------------------------------
function csrf_generate_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_validate_token(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Invalid or missing CSRF token.');
    }
}