<?php
declare(strict_types=1);

/**
 * PDF Download Proxy (Frontend)
 *
 * This proxy receives PDF download requests from public users
 * and forwards them to the backend (which is in the intranet).
 *
 * Architecture:
 * User (Internet) → Frontend Proxy → Backend PDF Generator → Frontend → User
 */

require_once __DIR__ . '/../../inc/bootstrap.php';

use Frontend\Config\FormConfig;
use Frontend\Services\MessageService as M;

// Set initial content type (will be overridden if PDF is successful)
header('Content-Type: text/html; charset=utf-8');

try {
    // Get token from request
    $token = $_GET['token'] ?? '';

    if (empty($token)) {
        throw new RuntimeException(M::get('errors.pdf.missing_token', 'Fehlender Download-Token'), 400);
    }

    // Validate token format (basic check)
    if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $token)) {
        throw new RuntimeException(M::get('errors.pdf.invalid_token_format', 'Ungültiges Token-Format'), 400);
    }

    // Build backend PDF URL
    $backendUrl = FormConfig::getBackendUrl();
    $backendPdfUrl = $backendUrl . '/../pdf/download.php?token=' . urlencode($token);

    // Forward request to backend
    $ch = curl_init($backendPdfUrl);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HEADER => true,  // Include headers in output
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($error) {
        error_log('Backend PDF request failed: ' . $error);
        throw new RuntimeException(M::get('errors.backend_unavailable', 'Backend nicht erreichbar'), 503);
    }

    // Split headers and body
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    // Check if successful (200 = PDF, anything else = error)
    if ($httpCode === 200) {
        // Forward relevant headers from backend
        $headerLines = explode("\r\n", $headers);

        foreach ($headerLines as $header) {
            // Forward these headers
            if (stripos($header, 'Content-Type:') === 0 ||
                stripos($header, 'Content-Disposition:') === 0 ||
                stripos($header, 'Content-Length:') === 0) {
                header($header);
            }
        }

        // Output PDF
        echo $body;
        exit;

    } else {
        // Backend returned an error - try to extract error message
        // The backend returns HTML error pages, but we'll show our own
        error_log("Backend PDF download failed with HTTP {$httpCode}");

        // Map common HTTP codes to user-friendly messages
        $errorMessage = match($httpCode) {
            400 => M::get('errors.pdf.invalid_request', 'Ungültige Anfrage'),
            403 => M::get('errors.pdf.invalid_token', 'Ungültiger oder abgelaufener Download-Link'),
            404 => M::get('errors.pdf.not_found', 'Anmeldung nicht gefunden'),
            default => M::get('errors.pdf.download_failed', 'PDF-Download fehlgeschlagen')
        };

        throw new RuntimeException($errorMessage, $httpCode);
    }

} catch (RuntimeException $e) {
    // User-friendly error page
    http_response_code($e->getCode() ?: 400);

    $errorTitle = M::get('errors.pdf.download_failed_title', 'PDF-Download nicht möglich');
    $errorMessage = $e->getMessage();
    $errorHint = M::get('errors.pdf.download_failed_hint', 'Der Download-Link ist möglicherweise abgelaufen oder ungültig. Links sind in der Regel 30 Minuten gültig.');

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
    // Unexpected error
    error_log('Unexpected error in PDF proxy: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());

    http_response_code(500);

    $errorTitle = M::get('errors.generic_error', 'Ein Fehler ist aufgetreten');
    $errorHint = M::withContact('errors.unexpected_error');

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
