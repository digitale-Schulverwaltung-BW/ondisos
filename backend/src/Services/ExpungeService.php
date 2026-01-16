<?php
// src/Services/ExpungeService.php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AnmeldungRepository;
use App\Config\Config;

class ExpungeService
{
    public function __construct(
        private AnmeldungRepository $repository
    ) {}

    /**
     * Auto-expunge old archived entries
     * 
     * @return array{deleted: int, ids: int[]}
     */
    public function autoExpunge(): array
    {
        $config = Config::getInstance();
        $daysOld = $config->autoExpungeDays;

        if ($daysOld <= 0) {
            // Auto-expunge disabled
            return ['deleted' => 0, 'ids' => []];
        }

        // Find expired archived entries
        $expired = $this->repository->findExpiredArchived($daysOld);
        
        if (empty($expired)) {
            return ['deleted' => 0, 'ids' => []];
        }

        $deletedIds = [];
        $deletedCount = 0;

        foreach ($expired as $anmeldung) {
            // Soft delete first (if not already deleted)
            if (!$anmeldung->deleted) {
                $this->repository->softDelete($anmeldung->id);
            }

            // Then hard delete after grace period
            // In production: you might want another grace period before hard delete
            // For now: hard delete immediately
            if ($this->repository->hardDelete($anmeldung->id)) {
                $deletedIds[] = $anmeldung->id;
                $deletedCount++;
            }
        }

        // Log the expunge
        error_log(sprintf(
            'Auto-expunge: Deleted %d archived entries older than %d days: [%s]',
            $deletedCount,
            $daysOld,
            implode(', ', $deletedIds)
        ));

        return [
            'deleted' => $deletedCount,
            'ids' => $deletedIds
        ];
    }

    /**
     * Preview what would be deleted
     * 
     * @return array{count: int, oldest: ?\DateTimeImmutable, newest: ?\DateTimeImmutable}
     */
    public function previewExpunge(): array
    {
        $config = Config::getInstance();
        $daysOld = $config->autoExpungeDays;

        if ($daysOld <= 0) {
            return ['count' => 0, 'oldest' => null, 'newest' => null];
        }

        $expired = $this->repository->findExpiredArchived($daysOld);
        
        if (empty($expired)) {
            return ['count' => 0, 'oldest' => null, 'newest' => null];
        }

        $dates = array_map(fn($a) => $a->updatedAt ?? $a->createdAt, $expired);
        
        return [
            'count' => count($expired),
            'oldest' => min($dates),
            'newest' => max($dates)
        ];
    }

    /**
     * Manually expunge specific IDs (admin action)
     * 
     * @param int[] $ids
     */
    public function manualExpunge(array $ids): int
    {
        $deletedCount = 0;

        foreach ($ids as $id) {
            if ($this->repository->hardDelete($id)) {
                $deletedCount++;
            }
        }

        error_log(sprintf(
            'Manual expunge: Deleted %d entries: [%s]',
            $deletedCount,
            implode(', ', $ids)
        ));

        return $deletedCount;
    }
}