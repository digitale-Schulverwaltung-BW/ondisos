<?php
declare(strict_types=1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Skip auth check if explicitly disabled (e.g., for login.php)
if (defined('SKIP_AUTH_CHECK') && SKIP_AUTH_CHECK === true) {
    return;
}

// Check if authentication is enabled
$authEnabled = filter_var($_ENV['AUTH_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

if (!$authEnabled) {
    // Auth disabled - allow access
    return;
}

// Auth enabled - check if user is logged in
if (empty($_SESSION['admin_logged_in'])) {
    // Not logged in - redirect to login page
    header('Location: login.php');
    exit;
}

// Optional: Check session timeout (if SESSION_LIFETIME is set)
$sessionLifetime = (int)($_ENV['SESSION_LIFETIME'] ?? 3600);
$loginTime = $_SESSION['login_time'] ?? 0;

if ($loginTime > 0 && (time() - $loginTime) > $sessionLifetime) {
    // Session expired
    session_destroy();
    header('Location: login.php?expired=1');
    exit;
}
