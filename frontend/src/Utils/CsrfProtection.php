<?php
// frontend/src/Utils/CsrfProtection.php

declare(strict_types=1);

namespace Frontend\Utils;

class CsrfProtection
{
    private const TOKEN_KEY = 'csrf_token';
    private const TOKEN_LENGTH = 32;

    /**
     * Generate or retrieve CSRF token
     */
    public static function getToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION[self::TOKEN_KEY])) {
            $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(self::TOKEN_LENGTH));
        }

        return $_SESSION[self::TOKEN_KEY];
    }

    /**
     * Validate CSRF token
     */
    public static function validate(string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION[self::TOKEN_KEY])) {
            return false;
        }

        return hash_equals($_SESSION[self::TOKEN_KEY], $token);
    }

    /**
     * Generate hidden input field with token
     */
    public static function inputField(): string
    {
        $token = self::getToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }

    /**
     * Get token as JSON for AJAX
     */
    public static function getTokenJson(): string
    {
        return json_encode(['token' => self::getToken()]);
    }

    /**
     * Regenerate token (call after successful form submission)
     */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(self::TOKEN_LENGTH));
    }
}