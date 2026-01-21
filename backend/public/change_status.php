<?php
// public/change_status.php

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

use App\Services\StatusService;
use App\Repositories\AnmeldungRepository;
use App\Services\MessageService as M;

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

// Get POST data
$id = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? 'status_change';
$newStatus = $_POST['status'] ?? '';
$returnUrl = $_POST['return_url'] ?? 'index.php';

// Validate inputs
if ($id <= 0) {
    $_SESSION['error'] = M::get('errors.invalid_id', 'Ungültige ID');
    header('Location: ' . $returnUrl);
    exit;
}

try {
    // Initialize service
    $repository = new AnmeldungRepository();
    $statusService = new StatusService($repository);

    // Handle action
    if ($action === 'delete') {
        // Soft delete
        $success = $statusService->delete($id);

        if ($success) {
            $_SESSION['success'] = M::format('success.deleted', ['id' => $id]);
        } else {
            $_SESSION['error'] = M::get('errors.delete_failed', 'Löschen fehlgeschlagen');
        }
    } else {
        // Status update
        if (empty($newStatus)) {
            $_SESSION['error'] = M::get('errors.invalid_status', 'Ungültiger Status');
            header('Location: ' . $returnUrl);
            exit;
        }

        $success = $statusService->updateStatus($id, $newStatus);

        if ($success) {
            $_SESSION['success'] = M::format('success.status_updated', [
                'id' => $id,
                'status' => M::get('status.' . $newStatus, $newStatus)
            ]);
        } else {
            $_SESSION['error'] = M::get('errors.status_update_failed', 'Status-Änderung fehlgeschlagen');
        }
    }
} catch (\InvalidArgumentException $e) {
    $_SESSION['error'] = $e->getMessage();
} catch (\Exception $e) {
    error_log('Status/Delete action error: ' . $e->getMessage());
    $_SESSION['error'] = M::withContact('errors.generic_error');
}

// Redirect back
header('Location: ' . $returnUrl);
exit;
