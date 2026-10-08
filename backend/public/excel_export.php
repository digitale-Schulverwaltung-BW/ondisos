<?php
// public/excel_export.php

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';

// Der Export setzt (je nach AUTO_MARK_AS_READ) den Status auf "exportiert": nur per POST mit CSRF-Token
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    die('Method Not Allowed');
}
csrf_validate();

use App\Repositories\AnmeldungRepository;
use App\Services\ExportService;
use App\Services\NominatimService;
use App\Services\SchoolLookupService;
use App\Services\SpreadsheetBuilder;
use App\Services\StatusService;
use App\Validators\AnmeldungValidator;

// Initialize dependencies
$repository = new AnmeldungRepository();
$statusService = new StatusService($repository);
$nominatimService = new NominatimService();
$schoolLookupService = new SchoolLookupService();
$exportService = new ExportService($repository, $statusService, $nominatimService, $schoolLookupService);

try {
    // Selected-IDs export (POST from bulk form with checkboxes)
    $selectedIds = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ids'])) {
        $rawIds = $_POST['ids'];
        if (is_array($rawIds)) {
            $selectedIds = array_values(array_filter(array_map('intval', $rawIds), fn($id) => $id > 0));
        }
    }

    // Single ID export
    $id = isset($_POST['id']) && $_POST['id'] !== ''
        ? (int)$_POST['id']
        : null;

    // Optional form filter
    $formularFilter = null;
    $rawForm = (string)($_POST['form'] ?? '');
    if ($rawForm !== '') {
        $formularFilter = trim($rawForm);
    }

    // Validate formular filter to prevent SQL injection
    AnmeldungValidator::validateFormularName($formularFilter);

    // Get export data: selected IDs → single record → all/filtered
    if ($selectedIds !== null && count($selectedIds) > 0) {
        $exportData = $exportService->getExportDataByIds($selectedIds, $formularFilter);
        $filename = $exportService->generateFilename($formularFilter);
    } elseif ($id !== null) {
        $exportData = $exportService->getExportDataById($id);
        $filename = $exportService->generateFilename(null, $id);
    } else {
        $exportData = $exportService->getExportData($formularFilter);
        $filename = $exportService->generateFilename($formularFilter);
    }

    // Build spreadsheet
    $builder = new SpreadsheetBuilder($exportService);
    $spreadsheet = $builder->build(
        $exportData['rows'],
        $exportData['columns'],
        $exportData['metadata']
    );

    // Set headers for download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Cache-Control: max-age=1'); // IE fix
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    header('Cache-Control: cache, must-revalidate');
    header('Pragma: public');

    // Output file
    $builder->save();
    exit;

} catch (Throwable $e) {
    // Log error
    error_log('Excel Export Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());

    // Show user-friendly error
    http_response_code(500);
    
    require __DIR__ . '/../inc/header.php';
    ?>
    <div class="container mt-4">
        <div class="alert alert-danger">
            <h4>Fehler beim Export</h4>
            <p>Der Excel-Export konnte nicht erstellt werden.</p>
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