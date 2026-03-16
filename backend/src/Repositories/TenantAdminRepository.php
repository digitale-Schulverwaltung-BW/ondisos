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
 * Key design decisions:
 * - Username uniqueness is enforced GLOBALLY (across all tenants), not per-tenant.
 *   This prevents credential confusion when a tenant admin logs in without specifying
 *   a tenant context — the username must unambiguously resolve to one account.
 * - The `active` column was added in Phase 3 (Plan 02) via migrate.php Step 4e.
 * - Protected getLastInsertId() enables unit testing without a live DB connection.
 *
 * The constructor accepts an optional injected mysqli connection,
 * which allows unit testing without a live database.
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
     * Enforces GLOBAL username uniqueness across all tenants before INSERT.
     * The `active` column defaults to 1 (enabled) on creation.
     *
     * @param array{tenant_id: int, username: string, password_hash: string} $data
     * @return int  The newly inserted record ID
     * @throws \InvalidArgumentException  when the username already exists (globally)
     */
    public function create(array $data): int
    {
        // Enforce global username uniqueness (not per-tenant)
        $check = $this->db->prepare(
            'SELECT COUNT(*) AS cnt FROM tenant_admins WHERE username = ?'
        );
        $check->bind_param('s', $data['username']);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();
        if ((int)($row['cnt'] ?? 0) > 0) {
            throw new \InvalidArgumentException('Username already exists');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO tenant_admins (tenant_id, username, password_hash, active) VALUES (?, ?, ?, 1)'
        );
        $stmt->bind_param('iss', $data['tenant_id'], $data['username'], $data['password_hash']);
        $stmt->execute();

        return $this->getLastInsertId();
    }

    /**
     * Return all admin records belonging to a specific tenant.
     *
     * Results are ordered by username ascending for consistent display.
     * Returns id, username, active — password_hash is intentionally excluded.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findByTenantId(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, username, active FROM tenant_admins WHERE tenant_id = ? ORDER BY username ASC'
        );
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Update the password_hash for a specific admin record.
     *
     * The caller is responsible for passing a pre-hashed value (password_hash()).
     * This method does not perform hashing — it stores the provided hash as-is.
     */
    public function resetPassword(int $id, string $passwordHash): void
    {
        $stmt = $this->db->prepare(
            'UPDATE tenant_admins SET password_hash = ? WHERE id = ?'
        );
        $stmt->bind_param('si', $passwordHash, $id);
        $stmt->execute();
    }

    /**
     * Enable or disable a tenant admin account.
     *
     * Converts the boolean $active to the integer 1 or 0 stored in the
     * `active` TINYINT(1) column added in Phase 3 (Plan 02, Step 4e).
     */
    public function toggleActive(int $id, bool $active): void
    {
        $activeInt = $active ? 1 : 0;
        $stmt      = $this->db->prepare(
            'UPDATE tenant_admins SET active = ? WHERE id = ?'
        );
        $stmt->bind_param('ii', $activeInt, $id);
        $stmt->execute();
    }

    /**
     * Reset any static caches. No-op (no static state in this repository).
     * Provided for test-isolation symmetry with other repositories.
     */
    public static function reset(): void
    {
        // No static cache — no-op
    }

    // =========================================================================
    // Protected helpers — overridable for unit testing
    // =========================================================================

    /**
     * Return the last insert ID from the current connection.
     *
     * Exposed as a protected method so that unit tests can override it via
     * an anonymous subclass, avoiding the "already closed" error that would
     * occur when accessing mysqli::$insert_id on a mock connection.
     */
    protected function getLastInsertId(): int
    {
        return (int)$this->db->insert_id;
    }
}
