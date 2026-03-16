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

    /**
     * Return all active tenants ordered by name.
     *
     * Used by the platform-admin tenant switcher dropdown in header.php to
     * populate the list of tenants the admin can switch into.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findAll(): array
    {
        $sql = 'SELECT id, name, slug FROM tenants WHERE active = 1 ORDER BY name ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
    }

    // =========================================================================
    // Write methods — implemented in Plan 02
    // =========================================================================

    /**
     * Create a new tenant row.
     *
     * Enforces slug uniqueness at the application layer before INSERT.
     * api_secret is stored as provided (raw hex) — consistent with the
     * existing default tenant seeding pattern.
     *
     * @param array{name: string, slug: string, origin: string|null, api_secret: string} $data
     * @return int  The newly inserted ID
     * @throws \InvalidArgumentException  when the slug already exists
     */
    public function create(array $data): int
    {
        // Enforce slug uniqueness before INSERT
        $check = $this->db->prepare('SELECT COUNT(*) AS cnt FROM tenants WHERE slug = ?');
        $check->bind_param('s', $data['slug']);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();
        if ((int)($row['cnt'] ?? 0) > 0) {
            throw new \InvalidArgumentException('Slug already exists');
        }

        $origin = $data['origin'] ?? null;
        $stmt   = $this->db->prepare(
            'INSERT INTO tenants (name, slug, origin, api_secret) VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('ssss', $data['name'], $data['slug'], $origin, $data['api_secret']);
        $stmt->execute();

        return $this->getLastInsertId();
    }

    /**
     * Update whitelisted fields (name, origin, active) for a tenant.
     *
     * api_secret is intentionally excluded from the whitelist to prevent
     * accidental overwrites. Secret rotation MUST use updateApiSecret().
     * Returns early without a DB call if no whitelisted fields are provided.
     *
     * @param array<string,mixed> $data  Fields to update (whitelist enforced)
     */
    public function update(int $id, array $data): void
    {
        $allowed  = ['name', 'origin', 'active'];
        $filtered = array_intersect_key($data, array_flip($allowed));

        if (empty($filtered)) {
            return; // nothing to update — no DB call needed
        }

        $setParts = [];
        $types    = '';
        $values   = [];

        foreach ($filtered as $column => $value) {
            $setParts[] = "{$column} = ?";
            $types     .= match ($column) {
                'active' => 'i',
                default  => 's',
            };
            $values[] = $value;
        }

        $types   .= 'i'; // for WHERE id = ?
        $values[] = $id;

        $sql  = 'UPDATE tenants SET ' . implode(', ', $setParts) . ' WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
    }

    /**
     * Rotate the API secret for a tenant.
     *
     * Dedicated method that bypasses the update() whitelist intentionally.
     * Using a separate method makes secret rotations explicit and auditable.
     */
    public function updateApiSecret(int $id, string $newSecret): void
    {
        $stmt = $this->db->prepare('UPDATE tenants SET api_secret = ? WHERE id = ?');
        $stmt->bind_param('si', $newSecret, $id);
        $stmt->execute();
    }

    /**
     * Return ALL tenants (active and inactive) for the platform admin UI.
     *
     * Unlike findAll(), this method does NOT filter by active=1 — it is
     * intended for the management list page where admins need to see and
     * manage all tenants regardless of status.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findAllForAdmin(): array
    {
        $sql  = 'SELECT id, name, slug, origin, active FROM tenants ORDER BY name ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
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
