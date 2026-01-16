<?php
// src/Repositories/AnmeldungRepository.php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Models\Anmeldung;
use mysqli;

class AnmeldungRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * Get paginated list of Anmeldungen
     * 
     * @return array{items: Anmeldung[], total: int}
     */
    public function findPaginated(
        ?string $formularFilter = null,
        ?string $statusFilter = null,
        int $limit = 25,
        int $offset = 0
    ): array {
        $params = [];
        $types = '';
        
        $countSql = "SELECT COUNT(*) AS cnt FROM anmeldungen WHERE deleted = 0";
        $sql = "SELECT id, formular, formular_version, name, email, status, created_at 
                FROM anmeldungen WHERE deleted = 0";

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

        // Get total count
        $countStmt = $this->db->prepare($countSql);
        if (!empty($params)) {
            $countStmt->bind_param($types, ...$params);
        }
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['cnt'];

        // Get items
        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
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
        $sql = "SELECT DISTINCT formular FROM anmeldungen ORDER BY formular ASC";
        $result = $this->db->query($sql);
        
        $forms = [];
        while ($row = $result->fetch_assoc()) {
            $forms[] = $row['formular'];
        }
        
        return $forms;
    }

    /**
     * Find single Anmeldung by ID
     */
    public function findById(int $id): ?Anmeldung
    {
        $sql = "SELECT id, formular, formular_version, name, email, status, created_at, data 
                FROM anmeldungen 
                WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return $row ? Anmeldung::fromArray($row) : null;
    }

    /**
     * Find all anmeldungen for export (non-deleted)
     * 
     * @return Anmeldung[]
     */
    public function findForExport(?string $formularFilter = null): array
    {
        $params = [];
        $types = '';
        
        $sql = "SELECT id, formular, formular_version, name, email, status, data, created_at
                FROM anmeldungen
                WHERE deleted = 0";

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
     * Update status of a single Anmeldung
     */
    public function updateStatus(int $id, string $newStatus): bool
    {
        $sql = "UPDATE anmeldungen 
                SET status = ?, updated_at = NOW() 
                WHERE id = ? AND deleted = 0";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('si', $newStatus, $id);
        
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

        // Sanitize IDs
        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        $sql = "UPDATE anmeldungen 
                SET status = ?, updated_at = NOW() 
                WHERE id IN ($placeholders) AND deleted = 0";
        
        $stmt = $this->db->prepare($sql);
        
        // Build types string: 's' for status, then 'i' for each ID
        $types = 's' . str_repeat('i', count($ids));
        
        // Merge status and IDs for bind_param
        $params = array_merge([$newStatus], $ids);
        
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        
        return $stmt->affected_rows;
    }

    /**
     * Soft delete (mark as deleted)
     */
    public function softDelete(int $id): bool
    {
        $sql = "UPDATE anmeldungen 
                SET deleted = 1, deleted_at = NOW(), updated_at = NOW() 
                WHERE id = ? AND deleted = 0";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $id);
        
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

        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        $sql = "UPDATE anmeldungen 
                SET deleted = 1, deleted_at = NOW(), updated_at = NOW() 
                WHERE id IN ($placeholders) AND deleted = 0";
        
        $stmt = $this->db->prepare($sql);
        $types = str_repeat('i', count($ids));
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        
        return $stmt->affected_rows;
    }

    /**
     * Hard delete (permanent removal) - for auto-expunge
     */
    public function hardDelete(int $id): bool
    {
        $sql = "DELETE FROM anmeldungen WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $id);
        
        return $stmt->execute() && $stmt->affected_rows > 0;
    }

    /**
     * Find archived entries older than X days
     * 
     * @return Anmeldung[]
     */
    public function findExpiredArchived(int $daysOld): array
    {
        $sql = "SELECT id, formular, formular_version, name, email, status, data, created_at, updated_at, deleted, deleted_at
                FROM anmeldungen 
                WHERE status = 'archiviert' 
                  AND deleted = 0
                  AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                ORDER BY updated_at ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $daysOld);
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
                WHERE deleted = 0
                GROUP BY status";
        
        $result = $this->db->query($sql);
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
                WHERE deleted = 1
                ORDER BY deleted_at DESC";
        
        $result = $this->db->query($sql);
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
        $sql = "UPDATE anmeldungen 
                SET deleted = 0, deleted_at = NULL, updated_at = NOW() 
                WHERE id = ? AND deleted = 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $id);
        
        return $stmt->execute() && $stmt->affected_rows > 0;
    }
    /**
     * Insert new anmeldung
     */
    public function insert(array $data): int
    {
        $sql = "INSERT INTO anmeldungen (
                    formular, formular_version, name, email, status, data, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param(
            'ssssss',
            $data['formular'],
            $data['formular_version'],
            $data['name'],
            $data['email'],
            $data['status'],
            $data['data']
        );
        
        $stmt->execute();
        
        return $stmt->insert_id;
    }    
}