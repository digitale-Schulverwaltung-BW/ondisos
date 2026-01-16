<?php
// public/download.php

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

use App\Controllers\DownloadController;

$controller = new DownloadController();

try {
    $fileName = $_GET['file'] ?? '';
    $controller->download($fileName);
} catch (InvalidArgumentException $e) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    
    require __DIR__ . '/../inc/header.php';
    ?>
    <div class="container mt-4">
        <div class="alert alert-danger">
            <h4>Fehler beim Download</h4>
            <p><?= htmlspecialchars($e->getMessage()) ?></p>
        </div>
        <a href="javascript:history.back()" class="btn btn-secondary">← Zurück</a>
    </div>
    <?php
    require __DIR__ . '/../inc/footer.php';
    exit;
} catch (Throwable $e) {
    error_log('Download error: ' . $e->getMessage());
    http_response_code(500);
    exit('Ein Fehler ist aufgetreten');
}