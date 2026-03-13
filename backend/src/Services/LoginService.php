<?php
// src/Services/LoginService.php

declare(strict_types=1);

namespace App\Services;

use mysqli;

/**
 * LoginService — encapsulates credential validation for both login paths.
 *
 * Two authentication paths:
 *   1. Platform admin — credentials from .env (ADMIN_USERNAME + ADMIN_PASSWORD_HASH)
 *   2. Tenant admin   — credentials from tenant_admins DB table, joined with tenants
 *
 * This class contains pure credential-check logic with no session side effects,
 * making it fully unit-testable without an HTTP context.
 *
 * login.php is responsible for:
 *   - Calling the appropriate method
 *   - Setting session keys after a successful call
 *   - CSRF validation, brute-force delay, and AuditLogger calls
 */
class LoginService
{
    /**
     * Attempt platform admin login via .env credentials.
     *
     * Reads ADMIN_USERNAME and ADMIN_PASSWORD_HASH from $_ENV.
     *
     * @param string $username Submitted username
     * @param string $password Submitted plaintext password
     * @return bool True on success, false on any failure
     */
    public function attemptPlatformAdminLogin(string $username, string $password): bool
    {
        $adminUsername = \App\Config\EnvLoader::get('ADMIN_USERNAME', '');
        $adminPasswordHash = \App\Config\EnvLoader::get('ADMIN_PASSWORD_HASH', '');

        if ($adminUsername === '' || $adminPasswordHash === '') {
            return false;
        }

        if ($username !== $adminUsername) {
            return false;
        }

        return password_verify($password, $adminPasswordHash);
    }

    /**
     * Attempt tenant admin login via DB lookup.
     *
     * Queries tenant_admins joined with tenants, verifies password, and returns
     * the DB row (including tenant_id) on success, or null on failure.
     *
     * Only returns a non-null result when:
     *   - A row with the given username exists in tenant_admins
     *   - The joined tenant is active (t.active = 1)
     *   - password_verify passes against the stored password_hash
     *
     * @param string $username Submitted username
     * @param string $password Submitted plaintext password
     * @param mysqli $db       Active database connection
     * @return array<string,mixed>|null Row with tenant_id on success, null on failure
     */
    public function attemptTenantAdminLogin(string $username, string $password, mysqli $db): ?array
    {
        $sql = 'SELECT ta.id, ta.username, ta.password_hash, ta.tenant_id, t.active
                FROM tenant_admins ta
                JOIN tenants t ON ta.tenant_id = t.id
                WHERE ta.username = ? AND t.active = 1
                LIMIT 1';

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return null;
        }

        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ($row === null || $row === false) {
            return null;
        }

        /** @var array<string,mixed> $row */
        if (!password_verify($password, (string)$row['password_hash'])) {
            return null;
        }

        return $row;
    }
}
