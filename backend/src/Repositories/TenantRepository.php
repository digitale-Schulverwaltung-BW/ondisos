<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use mysqli;

/**
 * TenantRepository — DB lookup for tenants by slug or id.
 *
 * Used by bootstrap.php to resolve the current tenant from the incoming
 * HTTP request (slug extracted from API key, subdomain, or header).
 *
 * The constructor accepts an optional injected mysqli connection,
 * which allows unit testing without a live database.
 */
class TenantRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * Find a tenant by its URL-safe slug.
     *
     * Returns the tenant row as an associative array, or null if not found.
     * Returns null immediately (without a DB query) when $slug is empty.
     *
     * @return array<string,mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }

        $sql = 'SELECT id, name, api_secret, slug, origin, active FROM tenants WHERE slug = ? LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return $row ?: null;
    }

    /**
     * Find a tenant by its integer primary key.
     *
     * Returns the tenant row as an associative array, or null if not found.
     *
     * @return array<string,mixed>|null
     */
    public function findById(int $id): ?array
    {
        $sql = 'SELECT id, name, api_secret, slug, origin, active FROM tenants WHERE id = ? LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return $row ?: null;
    }
}
