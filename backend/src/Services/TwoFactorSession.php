<?php
// src/Services/TwoFactorSession.php
// Intermediate login state between password and second factor

declare(strict_types=1);

namespace App\Services;

/**
 * `$_SESSION['pending_2fa']` marks "password correct, second factor (or setup) still missing".
 *
 * It is NOT a login: inc/auth.php only looks at `admin_logged_in`, so protected pages stay closed.
 * Only two_factor.php and two_factor_setup.php read this state. It expires after five minutes and
 * is dropped after too many wrong codes.
 */
final class TwoFactorSession
{
    public const KEY = 'pending_2fa';
    public const TTL = 300;
    public const MAX_ATTEMPTS = 5;

    public const MODE_CODE = 'code';
    public const MODE_SETUP = 'setup';

    public static function begin(
        string $adminKey,
        string $username,
        bool $isPlatformAdmin,
        ?int $tenantId,
        string $mode,
        ?int $now = null
    ): void {
        $_SESSION[self::KEY] = [
            'admin_key' => $adminKey,
            'username' => $username,
            'is_platform_admin' => $isPlatformAdmin,
            'tenant_id' => $tenantId,
            'mode' => $mode,
            'started' => $now ?? time(),
            'attempts' => 0,
        ];
    }

    /**
     * The pending state if it exists, is well-formed and not expired; otherwise null (and removed).
     *
     * @return array{admin_key: string, username: string, is_platform_admin: bool, tenant_id: ?int, mode: string, started: int, attempts: int}|null
     */
    public static function current(?int $now = null): ?array
    {
        $pending = $_SESSION[self::KEY] ?? null;
        if (!is_array($pending)
            || !isset($pending['admin_key'], $pending['username'], $pending['mode'], $pending['started'])
            || !is_string($pending['admin_key'])
            || !is_string($pending['username'])
            || !in_array($pending['mode'], [self::MODE_CODE, self::MODE_SETUP], true)
            || (($now ?? time()) - (int)$pending['started']) > self::TTL
        ) {
            unset($_SESSION[self::KEY]);

            return null;
        }

        return [
            'admin_key' => $pending['admin_key'],
            'username' => $pending['username'],
            'is_platform_admin' => !empty($pending['is_platform_admin']),
            'tenant_id' => isset($pending['tenant_id']) ? (int)$pending['tenant_id'] : null,
            'mode' => $pending['mode'],
            'started' => (int)$pending['started'],
            'attempts' => (int)($pending['attempts'] ?? 0),
        ];
    }

    /**
     * Counts a wrong code. False once the limit is reached: the pending state is gone and the
     * user has to start with the password again.
     */
    public static function recordFailedAttempt(): bool
    {
        $pending = self::current();
        if ($pending === null) {
            return false;
        }
        $attempts = $pending['attempts'] + 1;
        if ($attempts >= self::MAX_ATTEMPTS) {
            self::clear();

            return false;
        }
        $_SESSION[self::KEY]['attempts'] = $attempts;

        return true;
    }

    public static function clear(): void
    {
        unset($_SESSION[self::KEY]);
    }
}
