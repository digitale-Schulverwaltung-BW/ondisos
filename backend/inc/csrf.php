<?php
declare(strict_types=1);

/**
 * CSRF Protection Helper
 *
 * Provides functions for CSRF token generation and validation.
 * Tokens are stored in $_SESSION and must be included in POST requests.
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate or retrieve CSRF token for current session
 *
 * @return string The CSRF token
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token from POST request
 *
 * Compares the token from POST data with the session token using
 * timing-safe comparison to prevent timing attacks.
 *
 * @param string|null $postToken Token from POST request
 * @throws InvalidArgumentException If token is missing or invalid
 * @return void
 */
function csrf_validate(?string $postToken = null): void
{
    $postToken = $postToken ?? ($_POST['csrf_token'] ?? '');
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    // Check if tokens exist
    if (empty($sessionToken)) {
        throw new InvalidArgumentException('CSRF token not found in session. Please refresh the page and try again.');
    }

    if (empty($postToken)) {
        throw new InvalidArgumentException('CSRF token not provided. Please refresh the page and try again.');
    }

    // Timing-safe comparison to prevent timing attacks
    if (!hash_equals($sessionToken, $postToken)) {
        throw new InvalidArgumentException('Invalid CSRF token. Please refresh the page and try again.');
    }
}

/**
 * Output hidden CSRF token input field
 *
 * Use this in forms to automatically include the CSRF token:
 * <form method="post">
 *   <?php csrf_field(); ?>
 *   ...
 * </form>
 *
 * @return void
 */
function csrf_field(): void
{
    $token = csrf_token();
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Get CSRF token meta tag for use in HTML head
 *
 * Useful for AJAX requests:
 * <head>
 *   <?php csrf_meta(); ?>
 * </head>
 *
 * JavaScript can then read: document.querySelector('meta[name="csrf-token"]').content
 *
 * @return void
 */
function csrf_meta(): void
{
    $token = csrf_token();
    echo '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Regenerate CSRF token (use after login/logout)
 *
 * @return string The new CSRF token
 */
function csrf_regenerate(): string
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
