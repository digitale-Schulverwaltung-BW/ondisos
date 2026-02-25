<?php
/**
 * PDF Download Proxy
 *
 * Proxies PDF download requests from the browser to the backend,
 * because the backend is only reachable server-side (Intranet / Docker).
 *
 * Endpoint: /wp-admin/admin-ajax.php?action=ondisos_pdf_download&token=TOKEN
 *
 * @package Ondisos
 */

declare(strict_types=1);

namespace Ondisos;

use Frontend\Config\FormConfig;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PDF proxy handler
 */
class Pdf_Proxy
{
    /**
     * Constructor - register AJAX actions
     */
    public function __construct()
    {
        add_action('wp_ajax_ondisos_pdf_download', [$this, 'handle_download']);
        add_action('wp_ajax_nopriv_ondisos_pdf_download', [$this, 'handle_download']);
    }

    /**
     * Proxy a PDF download request to the backend.
     */
    public function handle_download(): void
    {
        $token = sanitize_text_field($_GET['token'] ?? '');

        if (empty($token)) {
            status_header(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Missing token';
            exit;
        }

        // Build backend PDF URL:
        // BACKEND_API_URL = http://host/api  →  PDF at http://host/pdf/download.php
        $apiUrl      = FormConfig::getBackendUrl();
        $backendBase = rtrim(preg_replace('#/api/?$#', '', $apiUrl), '/');
        $pdfUrl      = $backendBase . '/pdf/download.php?token=' . urlencode($token);

        // Fetch PDF from backend via cURL
        $responseHeaders = [];
        $ch = curl_init($pdfUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADERFUNCTION => static function ($ch, $header) use (&$responseHeaders): int {
                $trimmed = trim($header);
                if ($trimmed !== '') {
                    $responseHeaders[] = $trimmed;
                }
                return strlen($header);
            },
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode !== 200) {
            status_header($httpCode ?: 502);
            header('Content-Type: text/plain; charset=utf-8');
            echo $curlError ?: 'PDF generation failed (HTTP ' . $httpCode . ')';
            exit;
        }

        // Forward relevant response headers (Content-Type, Content-Disposition)
        status_header(200);
        foreach ($responseHeaders as $header) {
            if (stripos($header, 'Content-Type:') === 0
                || stripos($header, 'Content-Disposition:') === 0) {
                header($header);
            }
        }

        echo $body;
        exit;
    }
}
