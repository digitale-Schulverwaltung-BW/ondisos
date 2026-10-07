<?php
// src/Controllers/BulkActionsController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AnmeldungStatus;
use App\Services\StatusService;
use App\Services\AuditLogger;
use InvalidArgumentException;

class BulkActionsController
{
    /** Actions that set a status; the action name is the status value. */
    public const STATUS_ACTIONS = ['in_bearbeitung', 'akzeptiert', 'abgelehnt'];

    private const ALLOWED_ACTIONS = ['archive', 'delete', 'in_bearbeitung', 'akzeptiert', 'abgelehnt'];

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
            'in_bearbeitung', 'akzeptiert', 'abgelehnt' => $this->statusService->bulkUpdateStatus($ids, $action),
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
        if (in_array($action, self::STATUS_ACTIONS, true)) {
            $label = AnmeldungStatus::tryFromString($action)?->label() ?? $action;
            return 'auf „' . $label . '“ gesetzt';
        }

        return match($action) {
            'archive' => 'Archiviert',
            'delete' => 'Gelöscht',
            default => 'Verarbeitet'
        };
    }
}