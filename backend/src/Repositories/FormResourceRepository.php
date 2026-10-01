<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Config\TenantContext;
use App\Forms\ConflictException;
use App\Forms\Identifiers;
use mysqli;

/**
 * Published surveys and themes (form_resources), scoped to the current tenant.
 *
 * The config of a form refers to them by name ("form": "bs.json", "theme": "survey_theme.json").
 * Names are unique per tenant and kind, so two schools can both have a "bs.json".
 */
class FormResourceRepository
{
    public const KIND_SURVEY = 'survey';
    public const KIND_THEME  = 'theme';

    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * @return array{content: string, sha256: string, updated_at: ?string, updated_by: ?string}|null
     */
    public function find(string $kind, string $name, bool $forUpdate = false): ?array
    {
        $this->assertKindAndName($kind, $name);
        $tenantId = TenantContext::getTenantId();

        $sql = 'SELECT content, sha256, updated_at, updated_by FROM form_resources
                WHERE tenant_id = ? AND kind = ? AND name = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('iss', $tenantId, $kind, $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return $row ?: null;
    }

    /**
     * @return list<array{name: string, sha256: string, updated_at: ?string}>
     */
    public function listNames(string $kind): array
    {
        $this->assertKind($kind);
        $tenantId = TenantContext::getTenantId();

        $stmt = $this->db->prepare(
            'SELECT name, sha256, updated_at FROM form_resources WHERE tenant_id = ? AND kind = ? ORDER BY name ASC'
        );
        $stmt->bind_param('is', $tenantId, $kind);
        $stmt->execute();

        $rows = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Insert or replace a resource. The content must already be validated.
     *
     * @param string|null $expectedSha when set and a resource exists, its hash must match (else ConflictException)
     * @return string sha256 of the stored content
     */
    public function save(string $kind, string $name, string $content, ?string $updatedBy, ?string $expectedSha = null): string
    {
        $this->assertKindAndName($kind, $name);

        if ($expectedSha !== null) {
            $current = $this->find($kind, $name, forUpdate: true);
            if ($current !== null && !hash_equals($current['sha256'], $expectedSha)) {
                throw new ConflictException("'{$name}' wurde zwischenzeitlich geändert");
            }
        }

        $tenantId = TenantContext::getTenantId();
        $sha      = hash('sha256', $content);

        $stmt = $this->db->prepare(
            'INSERT INTO form_resources (tenant_id, kind, name, content, sha256, updated_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE content = VALUES(content), sha256 = VALUES(sha256), updated_by = VALUES(updated_by)'
        );
        $stmt->bind_param('isssss', $tenantId, $kind, $name, $content, $sha, $updatedBy);
        $stmt->execute();

        return $sha;
    }

    public function delete(string $kind, string $name): bool
    {
        $this->assertKindAndName($kind, $name);
        $tenantId = TenantContext::getTenantId();

        $stmt = $this->db->prepare('DELETE FROM form_resources WHERE tenant_id = ? AND kind = ? AND name = ?');
        $stmt->bind_param('iss', $tenantId, $kind, $name);
        $stmt->execute();
        return $stmt->affected_rows > 0;
    }

    private function assertKind(string $kind): void
    {
        if ($kind !== self::KIND_SURVEY && $kind !== self::KIND_THEME) {
            throw new \InvalidArgumentException('Ungültiger Ressourcentyp');
        }
    }

    private function assertKindAndName(string $kind, string $name): void
    {
        $this->assertKind($kind);
        if (!Identifiers::isValidResourceName($name)) {
            throw new \InvalidArgumentException('Ungültiger Ressourcenname');
        }
    }
}
