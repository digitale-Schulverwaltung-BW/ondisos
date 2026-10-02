<?php
declare(strict_types=1);

/**
 * Admin PDF Download Endpoint
 *
 * Generates a PDF confirmation on-demand for admin users.
 * Protected by session auth (unlike the token-based public endpoint).
 */

require_once __DIR__ . '/../../inc/bootstrap.php';
require_once __DIR__ . '/../../inc/auth.php';

use App\Services\PdfGeneratorService;
use App\Services\PdfLogoResolver;
use App\Services\PdfTemplateRenderer;
use App\Repositories\AnmeldungRepository;

header('Content-Type: text/html; charset=utf-8');

try {
    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        throw new RuntimeException('Ungültige Anmeldungs-ID', 400);
    }

    $repository = new AnmeldungRepository();
    $anmeldung = $repository->findById($id);

    if ($anmeldung === null || $anmeldung->deleted) {
        throw new RuntimeException('Anmeldung nicht gefunden', 404);
    }

    $pdfConfig = $anmeldung->pdfConfig ?? [];

    // Admin download always generates PDF regardless of enabled flag
    $pdfConfig['enabled'] = true;

    // Same logo order as the token download (PdfLogoResolver)
    $pdfConfig = (new PdfLogoResolver())->resolve($pdfConfig, $anmeldung->formular, $repository->findTenantIdById($id));

    $renderer = new PdfTemplateRenderer();
    $generator = new PdfGeneratorService($renderer);
    $generator->generateAndDownload($anmeldung, $pdfConfig);

} catch (RuntimeException $e) {
    http_response_code($e->getCode() ?: 400);
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><a href="../detail.php?id=' . (int)($_GET['id'] ?? 0) . '">&larr; Zurück</a></p>';
    exit;

} catch (\Throwable $e) {
    error_log('Unexpected error in admin PDF download: ' . $e->getMessage());
    http_response_code(500);
    echo '<p>Ein unerwarteter Fehler ist aufgetreten.</p>';
    echo '<p><a href="../detail.php?id=' . (int)($_GET['id'] ?? 0) . '">&larr; Zurück</a></p>';
    exit;
}
