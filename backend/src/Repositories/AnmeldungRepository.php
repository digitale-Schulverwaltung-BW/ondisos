<?php
// src/Repositories/AnmeldungRepository.php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Config\TenantContext;
use App\Models\Anmeldung;
use App\Services\AuditLogger;
use App\Validators\AnmeldungValidator;
use mysqli;

class AnmeldungRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    private const ALLOWED_SORT_COLUMNS = ['id', 'name', 'email', 'status', 'created_at'];

    /**
     * Get paginated list of Anmeldungen
     *
     * @return array{items: Anmeldung[], total: int}
     */
    public function findPaginated(
        ?string $formularFilter = null,
        ?string $statusFilter = null,
        ?string $nameSearch = null,
        ?string $emailSearch = null,
        int $limit = 25,
        int $offset = 0,
        string $sortColumn = 'id',
        string $sortDirection = 'DESC'
    ): array {
        // Defense-in-depth: validate formular filter at repository level
        AnmeldungValidator::validateFormularName($formularFilter);

        // Whitelist sort column and direction to prevent SQL injection
        $sortColumn = in_array($sortColumn, self::ALLOWED_SORT_COLUMNS, true) ? $sortColumn : 'id';
        $sortDirection = strtoupper($sortDirection) === 'ASC' ? 'ASC' : 'DESC';

        $params = [];
        $types = '';

        $countSql = "SELECT COUNT(*) AS cnt FROM anmeldungen WHERE deleted = 0";
        $sql = "SELECT id, formular, formular_version, name, email, status, created_at
                FROM anmeldungen WHERE deleted = 0";

        // Tenant isolation: skip filter only in all-tenants (platform admin) mode
        if (!TenantContext::isAllTenants()) {
            $tenantId = TenantContext::getTenantId();
            $countSql .= " AND tenant_id = ?";
            $sql .= " AND tenant_id = ?";
            $params[] = $tenantId;
            $types .= 'i';
        }

        if ($formularFilter !== null && $formularFilter !== '') {
            $countSql .= " AND formular = ?";
            $sql .= " AND formular = ?";
            $params[] = $formularFilter;
            $types .= 's';
        }

        if ($statusFilter !== null && $statusFilter !== '') {
            $countSql .= " AND status = ?";
            $sql .= " AND status = ?";
            $params[] = $statusFilter;
            $types .= 's';
        }

        if ($nameSearch !== null && $nameSearch !== '') {
            $likeName = '%' . $nameSearch . '%';
            $countSql .= " AND name LIKE ?";
            $sql .= " AND name LIKE ?";
            $params[] = $likeName;
            $types .= 's';
        }

        if ($emailSearch !== null && $emailSearch !== '') {
            $likeEmail = '%' . $emailSearch . '%';
            $countSql .= " AND email LIKE ?";
            $sql .= " AND email LIKE ?";
            $params[] = $likeEmail;
            $types .= 's';
        }

        // Get total count
        $countStmt = $this->db->prepare($countSql);
        if (!empty($params)) {
            $countStmt->bind_param($types, ...$params);
        }
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['cnt'];

        // Get items — sort column/direction are whitelisted above, safe to interpolate
        $sql .= " ORDER BY {$sortColumn} {$sortDirection} LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $result = $stmt->get_result();
        $items = [];

        while ($row = $result->fetch_assoc()) {
            $items[] = Anmeldung::fromArray($row);
        }

        return [
            'items' => $items,
            'total' => (int)$total
        ];
    }

    /**
     * Get all distinct form names
     *
     * @return string[]
     */
    public function getAllFormNames(): array
    {
        $sql = "SELECT DISTINCT formular FROM anmeldungen WHERE 1=1";
        $params = [];
        $types = '';

        if (!TenantContext::isAllTenants()) {
            $tenantId = TenantContext::getTenantId();
            $sql .= " AND tenant_id = ?";
            $params[] = $tenantId;
            $types .= 'i';
        }

        $sql .= " ORDER BY formular ASC";

        $stmt = $this->db->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $forms = [];
        while ($row = $result->fetch_assoc()) {
            $forms[] = $row['formular'];
        }

        return $forms;
    }

    /**
     * Find single Anmeldung by ID — with IDOR prevention.
     *
     * Two-query approach:
     *  1. Check existence globally (no tenant filter)
     *  2. Fetch with tenant filter
     * If (1) found a row but (2) returns nothing, an IDOR attempt is logged.
     */
    public function findById(int $id): ?Anmeldung
    {
        // All-tenants mode: no tenant filter needed
        if (TenantContext::isAllTenants()) {
            $sql = "SELECT id, formular, formular_version, name, email, status, created_at, data, pdf_config
                    FROM anmeldungen
                    WHERE id = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('i', $id);
            $stmt->execute();

            $result = $stmt->get_result();
            $row = $result->fetch_assoc();

            return $row ? Anmeldung::fromArray($row) : null;
        }

        $tenantId = TenantContext::getTenantId();

        // Query 1: check whether the record exists at all (cross-tenant)
        $existsStmt = $this->db->prepare("SELECT COUNT(*) AS cnt FROM anmeldungen WHERE id = ?");
        $existsStmt->bind_param('i', $id);
        $existsStmt->execute();
        $exists = (int)($existsStmt->get_result()->fetch_assoc()['cnt'] ?? 0) > 0;

        // Query 2: fetch with tenant filter
        $sql = "SELECT id, formular, formular_version, name, email, status, created_at, data, pdf_config
                FROM anmeldungen
                WHERE id = ? AND tenant_id = ?";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $id, $tenantId);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ($exists && $row === null) {
            // Record exists but belongs to another tenant — IDOR attempt
            AuditLogger::idorAttempt($id, $tenantId);
        }

        return $row ? Anmeldung::fromArray($row) : null;
    }

    /**
     * Read only the tenant_id of an Anmeldung — deliberately NOT tenant-scoped.
     *
     * For callers that are authorized by something other than a tenant session,
     * i.e. the PDF download endpoint: its HMAC token (PDF_TOKEN_SECRET) proves the
     * backend issued access to this id, but carries no tenant. The caller must
     * initialize TenantContext with the result and then load the row through the
     * normal scoped findById(). Never expose this to unauthenticated input.
     */
    public function findTenantIdById(int $id): ?int
    {
        $stmt = $this->db->prepare("SELECT tenant_id FROM anmeldungen WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return ($row && $row['tenant_id'] !== null) ? (int)$row['tenant_id'] : null;
    }

    /**
     * Find all anmeldungen for export (non-deleted)
     *
     * @return Anmeldung[]
     */
    public function findForExport(?string $formularFilter = null): array
    {
        // Defense-in-depth: validate formular filter at repository level
        AnmeldungValidator::validateFormularName($formularFilter);

        $params = [];
        $types = '';

        $sql = "SELECT id, formular, formular_version, name, email, status, data, created_at
                FROM anmeldungen
                WHERE deleted = 0";

        if (!TenantContext::isAllTenants()) {
            $tenantId = TenantContext::getTenantId();
            $sql .= " AND tenant_id = ?";
            $params[] = $tenantId;
            $types .= 'i';
        }

        if ($formularFilter !== null && $formularFilter !== '') {
            $sql .= " AND formular = ?";
            $params[] = $formularFilter;
            $types .= 's';
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->db->prepare($sql);

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = Anmeldung::fromArray($row);
        }

        return $items;
    }

    /**
     * Find specific anmeldungen by their IDs for export
     *
     * @param int[] $ids
     * @return Anmeldung[]
     */
    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if (!TenantContext::isAllTenants()) {
            $tenantId = TenantContext::getTenantId();
            $types = 'i' . str_repeat('i', count($ids));

            $sql = "SELECT id, formular, formular_version, name, email, status, data, created_at
                    FROM anmeldungen
                    WHERE deleted = 0 AND tenant_id = ? AND id IN ($placeholders)
                    ORDER BY created_at DESC";

            $params = array_merge([$tenantId], $ids);
        } else {
            $types = str_repeat('i', count($ids));

            $sql = "SELECT id, formular, formular_version, name, email, status, data, created_at
                    FROM anmeldungen
                    WHERE deleted = 0 AND id IN ($placeholders)
                    ORDER BY created_at DESC";

            $params = $ids;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $anmeldungen = [];
        while ($row = $result->fetch_assoc()) {
            $anmeldungen[] = Anmeldung::fromArray($row);
        }
        return $anmeldungen;
    }

    /**
     * Update status of a single Anmeldung
     */
    public function updateStatus(int $id, string $newStatus): bool
    {
        $tenantId = TenantContext::getTenantId();

        $sql = "UPDATE anmeldungen
                SET status = ?, updated_at = NOW()
                WHERE id = ? AND deleted = 0 AND tenant_id = ?";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('sii', $newStatus, $id, $tenantId);

        return $stmt->execute() && $stmt->affected_rows > 0;
    }

    /**
     * Bulk update status for multiple IDs
     *
     * @param int[] $ids
     */
    public function bulkUpdateStatus(array $ids, string $newStatus): int
    {
        if (empty($ids)) {
            return 0;
        }

        $tenantId = TenantContext::getTenantId();

        // Sanitize IDs
        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $sql = "UPDATE anmeldungen
                SET status = ?, updated_at = NOW()
                WHERE id IN ($placeholders) AND deleted = 0 AND tenant_id = ?";

        $stmt = $this->db->prepare($sql);

        // Build types string: 's' for status, then 'i' for each ID, then 'i' for tenant_id
        $types = 's' . str_repeat('i', count($ids)) . 'i';

        // Merge status, IDs, and tenant_id for bind_param
        $params = array_merge([$newStatus], $ids, [$tenantId]);

        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        return $stmt->affected_rows;
    }

    /**
     * Soft delete (mark as deleted)
     */
    public function softDelete(int $id): bool
    {
        $tenantId = TenantContext::getTenantId();

        $sql = "UPDATE anmeldungen
                SET deleted = 1, deleted_at = NOW(), updated_at = NOW()
                WHERE id = ? AND deleted = 0 AND tenant_id = ?";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $id, $tenantId);

        return $stmt->execute() && $stmt->affected_rows > 0;
    }

    /**
     * Bulk soft delete
     *
     * @param int[] $ids
     */
    public function bulkSoftDelete(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $tenantId = TenantContext::getTenantId();

        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $sql = "UPDATE anmeldungen
                SET deleted = 1, deleted_at = NOW(), updated_at = NOW()
                WHERE id IN ($placeholders) AND deleted = 0 AND tenant_id = ?";

        $stmt = $this->db->prepare($sql);
        $types = str_repeat('i', count($ids)) . 'i';
        $params = array_merge($ids, [$tenantId]);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        return $stmt->affected_rows;
    }

    /**
     * Hard delete (permanent removal) - for auto-expunge
     */
    public function hardDelete(int $id): bool
    {
        $tenantId = TenantContext::getTenantId();

        $sql = "DELETE FROM anmeldungen WHERE id = ? AND tenant_id = ?";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $id, $tenantId);

        return $stmt->execute() && $stmt->affected_rows > 0;
    }

    /**
     * Find archived entries older than X days
     *
     * @return Anmeldung[]
     */
    public function findExpiredArchived(int $daysOld): array
    {
        $params = [$daysOld];
        $types = 'i';

        $sql = "SELECT id, formular, formular_version, name, email, status, data, created_at, updated_at, deleted, deleted_at
                FROM anmeldungen
                WHERE status = 'archiviert'
                  AND deleted = 0
                  AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)";

        if (!TenantContext::isAllTenants()) {
            $tenantId = TenantContext::getTenantId();
            $sql .= " AND tenant_id = ?";
            $params[] = $tenantId;
            $types .= 'i';
        }

        $sql .= " ORDER BY updated_at ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $result = $stmt->get_result();
        $items = [];

        while ($row = $result->fetch_assoc()) {
            $items[] = Anmeldung::fromArray($row);
        }

        return $items;
    }

    /**
     * Get statistics
     */
    public function getStatistics(): array
    {
        $sql = "SELECT
                    status,
                    COUNT(*) as count
                FROM anmeldungen
                WHERE deleted = 0";

        $params = [];
        $types = '';

        if (!TenantContext::isAllTenants()) {
            $tenantId = TenantContext::getTenantId();
            $sql .= " AND tenant_id = ?";
            $params[] = $tenantId;
            $types .= 'i';
        }

        $sql .= " GROUP BY status";

        $stmt = $this->db->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $stats = [];

        while ($row = $result->fetch_assoc()) {
            $stats[$row['status']] = (int)$row['count'];
        }

        return $stats;
    }

    /**
     * Find all deleted entries (for trash view)
     *
     * @return Anmeldung[]
     */
    public function findDeleted(): array
    {
        $sql = "SELECT id, formular, formular_version, name, email, status, data,
                       created_at, updated_at, deleted, deleted_at
                FROM anmeldungen
                WHERE deleted = 1";

        $params = [];
        $types = '';

        if (!TenantContext::isAllTenants()) {
            $tenantId = TenantContext::getTenantId();
            $sql .= " AND tenant_id = ?";
            $params[] = $tenantId;
            $types .= 'i';
        }

        $sql .= " ORDER BY deleted_at DESC";

        $stmt = $this->db->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = Anmeldung::fromArray($row);
        }

        return $items;
    }

    /**
     * Restore a soft-deleted entry
     */
    public function restore(int $id): bool
    {
        $tenantId = TenantContext::getTenantId();

        $sql = "UPDATE anmeldungen
                SET deleted = 0, deleted_at = NULL, updated_at = NOW()
                WHERE id = ? AND deleted = 1 AND tenant_id = ?";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $id, $tenantId);

        return $stmt->execute() && $stmt->affected_rows > 0;
    }

    /**
     * Find the IDs of the previous and next non-deleted entries within the same formular
     *
     * @return array{prev: int|null, next: int|null}
     */
    public function findAdjacentIds(int $id, string $formular): array
    {
        // Tenant isolation: skip filter only in all-tenants (platform admin) mode
        $tenantSql = '';
        $tenantParams = [];
        $tenantTypes = '';
        if (!TenantContext::isAllTenants()) {
            $tenantSql = ' AND tenant_id = ?';
            $tenantParams = [TenantContext::getTenantId()];
            $tenantTypes = 'i';
        }

        $sql = "SELECT
                    (SELECT id FROM anmeldungen WHERE deleted = 0 AND formular = ? AND id < ?$tenantSql ORDER BY id DESC LIMIT 1) AS prev_id,
                    (SELECT id FROM anmeldungen WHERE deleted = 0 AND formular = ? AND id > ?$tenantSql ORDER BY id ASC  LIMIT 1) AS next_id";

        $stmt = $this->db->prepare($sql);
        $params = [$formular, $id, ...$tenantParams, $formular, $id, ...$tenantParams];
        $stmt->bind_param('si' . $tenantTypes . 'si' . $tenantTypes, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return [
            'prev' => $row['prev_id'] !== null ? (int)$row['prev_id'] : null,
            'next' => $row['next_id'] !== null ? (int)$row['next_id'] : null,
        ];
    }

    /**
     * Insert new anmeldung — tenant_id is auto-injected from TenantContext.
     * Callers cannot override or forget the tenant_id.
     */
    public function insert(array $data): int
    {
        $tenantId = TenantContext::getTenantId();

        $sql = "INSERT INTO anmeldungen (
                    tenant_id, formular, formular_version, name, email, status, data, pdf_config, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $pdfConfig = $data['pdf_config'] ?? null;

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param(
            'isssssss',
            $tenantId,
            $data['formular'],
            $data['formular_version'],
            $data['name'],
            $data['email'],
            $data['status'],
            $data['data'],
            $pdfConfig
        );

        $stmt->execute();

        return $stmt->insert_id;
    }
}
