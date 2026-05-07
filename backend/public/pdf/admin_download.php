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

    // Inject logo from backend env — same logic as token-based download.php
    // Use false in forms-config to explicitly suppress the logo.
    $logoConfigured = array_key_exists('logo', $pdfConfig);
    $logoExplicitlyDisabled = $logoConfigured && $pdfConfig['logo'] === false;

    if (!$logoExplicitlyDisabled && empty($pdfConfig['logo'])) {
        $logoEnvKey = 'PDF_LOGO_' . strtoupper($anmeldung->formular);
        $pdfConfig['logo'] = getenv($logoEnvKey) ?: getenv('PDF_LOGO_PATH') ?: null;
    }

    if ($pdfConfig['logo'] === false) {
        $pdfConfig['logo'] = null;
    }

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
