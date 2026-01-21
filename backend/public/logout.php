<?php
declare(strict_types=1);

// Bootstrap without auth check (we're logging out!)
define('SKIP_AUTH_CHECK', true);
require_once __DIR__ . '/../inc/bootstrap.php';

// Start session to access it
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear all session data
$_SESSION = [];

// Destroy session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy session
session_destroy();

// Redirect to login page
header('Location: login.php');
exit;
