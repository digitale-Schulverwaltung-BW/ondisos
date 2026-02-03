<?php
// public/bulk_actions.php

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';

use App\Controllers\BulkActionsController;
use App\Services\StatusService;
use App\Repositories\AnmeldungRepository;

// Validate CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
}

// Initialize dependencies
$repository = new AnmeldungRepository();
$statusService = new StatusService($repository);
$controller = new BulkActionsController($statusService);

try {
    $result = $controller->handle();
    
    // Prepare success message
    $actionLabel = BulkActionsController::getActionLabel($result['action']);
    $message = "{$result['count']} Einträge erfolgreich {$actionLabel}.";
    
    // Preserve filter parameters
    $redirectParams = [
        'bulk_success' => '1',
        'bulk_message' => $message
    ];
    
    if (isset($_POST['form']) && $_POST['form'] !== '') {
        $redirectParams['form'] = $_POST['form'];
    }
    
    // Redirect back to index
    $redirectUrl = 'index.php?' . http_build_query($redirectParams);
    header("Location: $redirectUrl");
    exit;

} catch (InvalidArgumentException $e) {
    // User error (no items selected, invalid action, etc.)
    require __DIR__ . '/../inc/header.php';
    ?>
    <div class="container mt-4">
        <div class="alert alert-warning">
            <h4>Fehler</h4>
            <p><?= htmlspecialchars($e->getMessage()) ?></p>
        </div>
        <a href="index.php" class="btn btn-secondary">← Zurück zur Übersicht</a>
    </div>
    <?php
    require __DIR__ . '/../inc/footer.php';
    exit;

} catch (Throwable $e) {
    // System error
    error_log('Bulk Action Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    http_response_code(500);
    require __DIR__ . '/../inc/header.php';
    ?>
    <div class="container mt-4">
        <div class="alert alert-danger">
            <h4>Systemfehler</h4>
            <p>Die Aktion konnte nicht durchgeführt werden. Bitte versuchen Sie es später erneut.</p>
            <?php if (ini_get('display_errors')): ?>
                <pre class="mt-3 small"><?= htmlspecialchars($e->getMessage()) ?></pre>
            <?php endif; ?>
        </div>
        <a href="index.php" class="btn btn-secondary">← Zurück zur Übersicht</a>
    </div>
    <?php
    require __DIR__ . '/../inc/footer.php';
    exit;
}