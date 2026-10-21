<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Config\TenantContext;
use mysqli;

/**
 * Signature handoffs (3.2.0a): one row per QR code, scoped to the current tenant.
 *
 * Every query filters by tenant_id; a session id of another tenant is simply not found.
 * Times are Unix seconds and passed in by the caller (SignatureHandoffService owns the clock).
 * Single use is enforced here with conditional UPDATE/DELETE statements, not by read-then-write.
 */
class SignatureHandoffRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * @param array{id: string, form_key: string, field_name: string, field_title: string, width: int, height: int,
     *              pen_color: ?string, background_color: ?string, write_hash: string, read_hash: string,
     *              created_at: int, expires_at: int} $row
     */
    public function insert(array $row): void
    {
        $tenantId = TenantContext::getTenantId();
        $stmt = $this->db->prepare(
            'INSERT INTO signature_handoffs
                (id, tenant_id, form_key, field_name, field_title, width, height, pen_color, background_color,
                 write_hash, read_hash, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'sisssiissssii',
            $row['id'],
            $tenantId,
            $row['form_key'],
            $row['field_name'],
            $row['field_title'],
            $row['width'],
            $row['height'],
            $row['pen_color'],
            $row['background_color'],
            $row['write_hash'],
            $row['read_hash'],
            $row['created_at'],
            $row['expires_at']
        );
        $stmt->execute();
        $stmt->close();
    }

    /**
     * @return array{id: string, form_key: string, field_name: string, field_title: string, width: int, height: int,
     *               pen_color: ?string, background_color: ?string, write_hash: string, read_hash: string, png: ?string,
     *               status: string, created_at: int, expires_at: int}|null
     */
    public function find(string $id): ?array
    {
        $tenantId = TenantContext::getTenantId();
        $stmt = $this->db->prepare(
            'SELECT id, form_key, field_name, field_title, width, height, pen_color, background_color,
                    write_hash, read_hash, png, status,
                    created_at, expires_at
             FROM signature_handoffs WHERE id = ? AND tenant_id = ? LIMIT 1'
        );
        $stmt->bind_param('si', $id, $tenantId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }

        return [
            'id' => (string)$row['id'],
            'form_key' => (string)$row['form_key'],
            'field_name' => (string)$row['field_name'],
            'field_title' => (string)$row['field_title'],
            'width' => (int)$row['width'],
            'height' => (int)$row['height'],
            'pen_color' => $row['pen_color'] !== null ? (string)$row['pen_color'] : null,
            'background_color' => $row['background_color'] !== null ? (string)$row['background_color'] : null,
            'write_hash' => (string)$row['write_hash'],
            'read_hash' => (string)$row['read_hash'],
            'png' => $row['png'] !== null ? (string)$row['png'] : null,
            'status' => (string)$row['status'],
            'created_at' => (int)$row['created_at'],
            'expires_at' => (int)$row['expires_at'],
        ];
    }

    /** Sessions of the current tenant that have not expired yet. */
    public function countOpen(int $now): int
    {
        $tenantId = TenantContext::getTenantId();
        $stmt = $this->db->prepare('SELECT COUNT(*) AS cnt FROM signature_handoffs WHERE tenant_id = ? AND expires_at > ?');
        $stmt->bind_param('ii', $tenantId, $now);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row['cnt'] ?? 0);
    }

    /** open → scanned; false if the session is gone, expired or further along. */
    public function markScanned(string $id, int $now): bool
    {
        $tenantId = TenantContext::getTenantId();
        $stmt = $this->db->prepare(
            "UPDATE signature_handoffs SET status = 'scanned'
             WHERE id = ? AND tenant_id = ? AND status = 'open' AND expires_at > ?"
        );
        $stmt->bind_param('sii', $id, $tenantId, $now);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();

        return $changed;
    }

    /** open|scanned → signed with the image; false if already signed or expired (single use). */
    public function storeSignature(string $id, string $png, int $now): bool
    {
        $tenantId = TenantContext::getTenantId();
        $stmt = $this->db->prepare(
            "UPDATE signature_handoffs SET png = ?, status = 'signed'
             WHERE id = ? AND tenant_id = ? AND status IN ('open', 'scanned') AND expires_at > ?"
        );
        $stmt->bind_param('ssii', $png, $id, $tenantId, $now);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();

        return $changed;
    }

    /** Deletes one session; true only for the caller that actually removed it (one pickup). */
    public function delete(string $id): bool
    {
        $tenantId = TenantContext::getTenantId();
        $stmt = $this->db->prepare('DELETE FROM signature_handoffs WHERE id = ? AND tenant_id = ?');
        $stmt->bind_param('si', $id, $tenantId);
        $stmt->execute();
        $deleted = $stmt->affected_rows === 1;
        $stmt->close();

        return $deleted;
    }

    /** Expired sessions of the current tenant; returns the number removed. */
    public function deleteExpired(int $now): int
    {
        $tenantId = TenantContext::getTenantId();
        $stmt = $this->db->prepare('DELETE FROM signature_handoffs WHERE tenant_id = ? AND expires_at <= ?');
        $stmt->bind_param('ii', $tenantId, $now);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();

        return max(0, $deleted);
    }
}
