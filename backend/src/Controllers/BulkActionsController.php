<?php
// src/Controllers/BulkActionsController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\StatusService;
use App\Services\AuditLogger;
use InvalidArgumentException;

class BulkActionsController
{
    private const ALLOWED_ACTIONS = ['archive', 'delete'];

    public function __construct(
        private StatusService $statusService
    ) {}

    /**
     * Handle bulk action request
     */
    public function handle(): array
    {
        // Validate request method
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new InvalidArgumentException('Invalid request method');
        }

        // Get action
        $action = $_POST['action'] ?? '';
        
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            throw new InvalidArgumentException('Invalid action');
        }

        // Get selected IDs
        $ids = $_POST['ids'] ?? [];
        
        if (!is_array($ids) || empty($ids)) {
            throw new InvalidArgumentException('No items selected');
        }

        // Sanitize IDs
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, fn($id) => $id > 0);

        if (empty($ids)) {
            throw new InvalidArgumentException('No valid IDs');
        }

        // Perform action
        $affectedCount = match($action) {
            'archive' => $this->statusService->bulkArchive($ids),
            'delete' => $this->statusService->bulkDelete($ids),
            default => throw new InvalidArgumentException('Unknown action')
        };

        AuditLogger::bulkAction($action, array_values($ids));

        return [
            'success' => true,
            'action' => $action,
            'count' => $affectedCount,
            'ids' => $ids
        ];
    }

    /**
     * Get action label for display
     */
    public static function getActionLabel(string $action): string
    {
        return match($action) {
            'archive' => 'Archiviert',
            'delete' => 'Gelöscht',
            default => 'Verarbeitet'
        };
    }
}