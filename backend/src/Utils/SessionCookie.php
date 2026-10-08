<?php
// src/Utils/SessionCookie.php

declare(strict_types=1);

namespace App\Utils;

/**
 * Setzt die Cookie-Parameter der Sitzung (HttpOnly, SameSite, Secure),
 * unabhängig von der php.ini des Hosts. Vor session_start() aufrufen.
 */
final class SessionCookie
{
    /**
     * @return array{lifetime: int, path: string, secure: bool, httponly: bool, samesite: string}
     */
    public static function params(bool $secure): array
    {
        return [
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    public static function configure(bool $secure): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params(self::params($secure));
        }
    }
}
