<?php
// src/Services/StatusService.php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AnmeldungRepository;
use App\Models\AnmeldungStatus;

class StatusService
{
    public function __construct(
        private AnmeldungRepository $repository
    ) {}

    /**
     * Mark anmeldung as read (if currently "neu")
     */
    public function markAsRead(int $id): bool
    {
        // Only mark as read if status is currently "neu"
        $anmeldung = $this->repository->findById($id);
        
        if ($anmeldung === null) {
            return false;
        }

        if ($anmeldung->status === 'neu') {
            return $this->repository->updateStatus($id, AnmeldungStatus::GELESEN->value);
        }

        return true; // Already read or processed
    }

    /**
     * Mark multiple anmeldungen as read
     * 
     * @param int[] $ids
     */
    public function markMultipleAsRead(array $ids): int
    {
        $markedCount = 0;

        foreach ($ids as $id) {
            if ($this->markAsRead($id)) {
                $markedCount++;
            }
        }

        return $markedCount;
    }

    /**
     * Archive single anmeldung
     */
    public function archive(int $id): bool
    {
        return $this->repository->updateStatus($id, AnmeldungStatus::ARCHIVIERT->value);
    }

    /**
     * Archive multiple anmeldungen
     * 
     * @param int[] $ids
     */
    public function bulkArchive(array $ids): int
    {
        return $this->repository->bulkUpdateStatus($ids, AnmeldungStatus::ARCHIVIERT->value);
    }

    /**
     * Soft delete single anmeldung
     */
    public function delete(int $id): bool
    {
        return $this->repository->softDelete($id);
    }

    /**
     * Soft delete multiple anmeldungen
     * 
     * @param int[] $ids
     */
    public function bulkDelete(array $ids): int
    {
        return $this->repository->bulkSoftDelete($ids);
    }

    /**
     * Update status for single anmeldung
     */
    public function updateStatus(int $id, string $newStatus): bool
    {
        // Validate status
        $statusEnum = AnmeldungStatus::tryFromString($newStatus);
        
        if ($statusEnum === null) {
            throw new \InvalidArgumentException("Invalid status: $newStatus");
        }

        return $this->repository->updateStatus($id, $statusEnum->value);
    }

    /**
     * Get status statistics
     */
    public function getStatistics(): array
    {
        return $this->repository->getStatistics();
    }
}