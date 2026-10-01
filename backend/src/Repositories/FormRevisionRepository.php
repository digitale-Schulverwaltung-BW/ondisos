<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Config\TenantContext;
use App\Services\AuditLogger;
use mysqli;

/**
 * Append-only history of everything that was live for a form (config, survey, theme),
 * scoped to the current tenant.
 *
 * Revisions are never updated. pruneOldest() only removes the oldest ones beyond a retention count.
 */
class FormRevisionRepository
{
    public const KIND_CONFIG = 'config';
    public const KIND_SURVEY = 'survey';
    public const KIND_THEME  = 'theme';

    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * @return int id of the new revision
     */
    public function add(
        string $formKey,
        string $kind,
        ?string $name,
        string $content,
        ?string $note,
        ?string $createdBy
    ): int {
        if (!in_array($kind, [self::KIND_CONFIG, self::KIND_SURVEY, self::KIND_THEME], true)) {
            throw new \InvalidArgumentException('Ungültiger Revisionstyp');
        }
        $tenantId = TenantContext::getTenantId();
        $sha      = hash('sha256', $content);

        $stmt = $this->db->prepare(
            'INSERT INTO form_revisions (tenant_id, form_key, kind, name, content, sha256, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isssssss', $tenantId, $formKey, $kind, $name, $content, $sha, $note, $createdBy);
        $stmt->execute();

        return $this->getLastInsertId();
    }

    /**
     * Newest revision of a kind for a form (used to avoid storing the same state twice).
     *
     * @return array{id: int, sha256: string}|null
     */
    public function latest(string $formKey, string $kind): ?array
    {
        $tenantId = TenantContext::getTenantId();
        $stmt = $this->db->prepare(
            'SELECT id, sha256 FROM form_revisions WHERE tenant_id = ? AND form_key = ? AND kind = ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->bind_param('iss', $tenantId, $formKey, $kind);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return $row ? ['id' => (int)$row['id'], 'sha256' => (string)$row['sha256']] : null;
    }

    /**
     * Revision list without contents, newest first.
     *
     * @return list<array{id: int, kind: string, name: ?string, sha256: string, note: ?string, created_by: ?string, created_at: string}>
     */
    public function list(string $formKey, ?string $kind = null, int $limit = 50): array
    {
        $tenantId = TenantContext::getTenantId();
        $limit    = max(1, min(200, $limit));

        if ($kind === null) {
            $stmt = $this->db->prepare(
                'SELECT id, kind, name, sha256, note, created_by, created_at FROM form_revisions
                 WHERE tenant_id = ? AND form_key = ? ORDER BY id DESC LIMIT ?'
            );
            $stmt->bind_param('isi', $tenantId, $formKey, $limit);
        } else {
            $stmt = $this->db->prepare(
                'SELECT id, kind, name, sha256, note, created_by, created_at FROM form_revisions
                 WHERE tenant_id = ? AND form_key = ? AND kind = ? ORDER BY id DESC LIMIT ?'
            );
            $stmt->bind_param('issi', $tenantId, $formKey, $kind, $limit);
        }
        $stmt->execute();

        $rows = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * One revision including its content. A revision of another tenant is reported as an
     * IDOR attempt and returned as null, exactly like a missing one.
     *
     * @return array{id: int, form_key: string, kind: string, name: ?string, content: string, sha256: string, note: ?string, created_by: ?string, created_at: string}|null
     */
    public function find(int $id): ?array
    {
        $tenantId = TenantContext::getTenantId();
        $stmt = $this->db->prepare(
            'SELECT id, form_key, kind, name, content, sha256, note, created_by, created_at
             FROM form_revisions WHERE id = ? AND tenant_id = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $id, $tenantId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ($row) {
            $row['id'] = (int)$row['id'];
            return $row;
        }

        // Not found for this tenant: distinguish "does not exist" from "belongs to someone else" for the audit log.
        $check = $this->db->prepare('SELECT COUNT(*) AS cnt FROM form_revisions WHERE id = ?');
        $check->bind_param('i', $id);
        $check->execute();
        $exists = (int)($check->get_result()->fetch_assoc()['cnt'] ?? 0) > 0;
        if ($exists) {
            AuditLogger::idorAttempt($id, $tenantId);
        }

        return null;
    }

    /**
     * Keep only the newest $keep revisions per form and kind.
     */
    public function pruneOldest(string $formKey, string $kind, int $keep = 50): int
    {
        $tenantId = TenantContext::getTenantId();
        $keep     = max(1, $keep);

        $stmt = $this->db->prepare(
            'DELETE FROM form_revisions
             WHERE tenant_id = ? AND form_key = ? AND kind = ?
               AND id NOT IN (
                   SELECT id FROM (
                       SELECT id FROM form_revisions WHERE tenant_id = ? AND form_key = ? AND kind = ?
                       ORDER BY id DESC LIMIT ?
                   ) AS newest
               )'
        );
        $stmt->bind_param('ississi', $tenantId, $formKey, $kind, $tenantId, $formKey, $kind, $keep);
        $stmt->execute();

        return $stmt->affected_rows;
    }

    /** Overridable so unit tests need no real connection (same pattern as TenantRepository). */
    protected function getLastInsertId(): int
    {
        return (int)$this->db->insert_id;
    }
}
