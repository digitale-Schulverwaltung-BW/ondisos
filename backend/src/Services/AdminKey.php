<?php
// src/Services/AdminKey.php
// Stable key of an admin account for second-factor data

declare(strict_types=1);

namespace App\Services;

/**
 * `tenant:<tenant_admins.id>` for tenant admins, `platform:<ADMIN_USERNAME>` for the platform admin
 * from the .env (it has no database row). Renaming ADMIN_USERNAME therefore starts a new account
 * without second factor: reset with reset-2fa.php if needed.
 */
final class AdminKey
{
    public static function forPlatform(string $username): string
    {
        return 'platform:' . $username;
    }

    public static function forTenantAdmin(int $tenantAdminId): string
    {
        return 'tenant:' . $tenantAdminId;
    }
}
