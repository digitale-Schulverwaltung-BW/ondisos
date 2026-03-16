<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use mysqli;

/**
 * TenantAdminRepository — CRUD operations for tenant_admins table.
 *
 * Manages per-tenant admin accounts (non-platform admins).
 * Each tenant admin belongs to exactly one tenant.
 *
 * The constructor accepts an optional injected mysqli connection,
 * which allows unit testing without a live database.
 *
 * NOTE: All methods throw RuntimeException('Not implemented') until
 * Plan 02 implements the actual SQL. This skeleton exists to satisfy
 * Wave 0 Nyquist compliance — tests can load and call these methods.
 */
class TenantAdminRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * Create a new tenant admin record.
     *
     * @param array{tenant_id: int, username: string, password_hash: string} $data
     * @return int  The newly inserted record ID
     */
    public function create(array $data): int
    {
        throw new \RuntimeException('Not implemented');
    }

    /**
     * Return all admin records belonging to a specific tenant.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findByTenantId(int $tenantId): array
    {
        throw new \RuntimeException('Not implemented');
    }

    /**
     * Update the password_hash for a specific admin record.
     */
    public function resetPassword(int $id, string $passwordHash): void
    {
        throw new \RuntimeException('Not implemented');
    }

    /**
     * Enable or disable a tenant admin account.
     *
     * Note: the `active` column does not yet exist in the schema —
     * it will be added by the migration in Plan 02.
     */
    public function toggleActive(int $id, bool $active): void
    {
        throw new \RuntimeException('Not implemented');
    }

    /**
     * Reset any static caches. No-op for now (no static state).
     * Provided for test-isolation symmetry with other repositories.
     */
    public static function reset(): void
    {
        // No static cache — no-op
    }
}
