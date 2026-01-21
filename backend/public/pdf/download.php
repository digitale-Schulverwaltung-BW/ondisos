<?php
declare(strict_types=1);

/**
 * PDF Download Endpoint
 *
 * Validates token and generates PDF on-demand for download.
 * No permanent storage - PDF is generated for each request.
 */

// Skip auth check - this endpoint uses token-based authentication
define('SKIP_AUTH_CHECK', true);

require_once __DIR__ . '/../../inc/bootstrap.php';

use App\Services\PdfGeneratorService;
use App\Services\PdfTokenService;
use App\Services\PdfTemplateRenderer;
use App\Repositories\AnmeldungRepository;
use App\Config\FormConfig;
use App\Services\MessageService as M;

// Set content type header (will be overridden by mPDF if successful)
header('Content-Type: text/html; charset=utf-8');

try {
    // Get token from request
    $token = $_GET['token'] ?? '';

    if (empty($token)) {
        throw new RuntimeException(M::get('pdf.errors.missing_token', 'Fehlender Download-Token'), 400);
    }

    // Validate token and get Anmeldung ID
    $tokenService = new PdfTokenService();
    $anmeldungId = $tokenService->validateToken($token);

    if ($anmeldungId === null) {
        throw new RuntimeException(M::get('pdf.errors.invalid_token', 'Ungültiger oder abgelaufener Token'), 403);
    }

    // Load Anmeldung from database
    $repository = new AnmeldungRepository();
    $anmeldung = $repository->findById($anmeldungId);

    if ($anmeldung === null) {
        throw new RuntimeException(M::get('errors.not_found', 'Anmeldung nicht gefunden'), 404);
    }

    // Get PDF config for this form
    $formKey = $anmeldung->formular;

    if (!FormConfig::exists($formKey)) {
        throw new RuntimeException(M::get('errors.unknown_form', 'Unbekanntes Formular'), 400);
    }

    $formConfig = FormConfig::get($formKey);
    $pdfConfig = $formConfig['pdf'] ?? null;

    if (!$pdfConfig || !($pdfConfig['enabled'] ?? false)) {
        throw new RuntimeException(M::get('pdf.errors.not_enabled', 'PDF nicht aktiviert für dieses Formular'), 403);
    }

    // Generate and download PDF
    $renderer = new PdfTemplateRenderer();
    $generator = new PdfGeneratorService($renderer);

    // This will set headers and output PDF directly
    $generator->generateAndDownload($anmeldung, $pdfConfig);

    // Script terminates here if successful

} catch (RuntimeException $e) {
    // User-friendly error page
    http_response_code($e->getCode() ?: 400);

    $errorTitle = M::get('pdf.errors.download_failed_title', 'PDF-Download nicht möglich');
    $errorMessage = $e->getMessage();
    $errorHint = M::get('pdf.errors.download_failed_hint', 'Der Link ist möglicherweise abgelaufen oder ungültig.');

    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($errorTitle) ?></title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .error-container {
                background: white;
                border-radius: 12px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                max-width: 500px;
                width: 100%;
                padding: 40px;
                text-align: center;
            }
            .error-icon {
                font-size: 64px;
                margin-bottom: 20px;
            }
            h1 {
                color: #dc3545;
                font-size: 24px;
                margin-bottom: 15px;
            }
            .error-message {
                color: #666;
                font-size: 16px;
                margin-bottom: 10px;
                line-height: 1.6;
            }
            .error-hint {
                color: #999;
                font-size: 14px;
                margin-top: 20px;
                padding: 15px;
                background: #f8f9fa;
                border-radius: 6px;
                border-left: 4px solid #ffc107;
            }
            .back-link {
                display: inline-block;
                margin-top: 25px;
                padding: 12px 24px;
                background: #667eea;
                color: white;
                text-decoration: none;
                border-radius: 6px;
                transition: background 0.3s;
            }
            .back-link:hover {
                background: #5568d3;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">📄❌</div>
            <h1><?= htmlspecialchars($errorTitle) ?></h1>
            <p class="error-message"><?= htmlspecialchars($errorMessage) ?></p>
            <div class="error-hint">
                <strong>💡 Hinweis:</strong><br>
                <?= htmlspecialchars($errorHint) ?>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;

} catch (\Throwable $e) {
    // Unexpected error - log and show generic message
    error_log('Unexpected error in PDF download: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());

    http_response_code(500);

    $errorTitle = M::get('errors.generic_error', 'Ein Fehler ist aufgetreten');
    $errorHint = M::withContact('pdf.errors.unexpected_error');

    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Fehler</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .error-container {
                background: white;
                border-radius: 12px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                max-width: 500px;
                width: 100%;
                padding: 40px;
                text-align: center;
            }
            .error-icon { font-size: 64px; margin-bottom: 20px; }
            h1 { color: #dc3545; font-size: 24px; margin-bottom: 15px; }
            .error-hint {
                color: #666;
                margin-top: 20px;
                padding: 15px;
                background: #f8f9fa;
                border-radius: 6px;
                line-height: 1.6;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">⚠️</div>
            <h1><?= htmlspecialchars($errorTitle) ?></h1>
            <div class="error-hint">
                <?= htmlspecialchars($errorHint) ?>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}
