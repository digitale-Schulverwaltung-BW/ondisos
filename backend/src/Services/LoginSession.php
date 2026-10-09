<?php
// src/Services/LoginSession.php
// Creates the logged-in session (after password and, if required, second factor)

declare(strict_types=1);

namespace App\Services;

final class LoginSession
{
    /**
     * Regenerates the session ID (session fixation) and sets the keys inc/auth.php relies on.
     * Called only after the login is complete: password and, where required, second factor.
     */
    public static function establish(string $username, bool $isPlatformAdmin, ?int $tenantId, string $adminKey): void
    {
        session_regenerate_id(true);
        TwoFactorSession::clear();

        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_key'] = $adminKey;
        $_SESSION['login_time'] = time();
        $_SESSION['is_platform_admin'] = $isPlatformAdmin;
        if (!$isPlatformAdmin && $tenantId !== null) {
            $_SESSION['tenant_id'] = $tenantId;
        }
    }
}
