<?php
// frontend/src/Services/BackendApiClient.php

declare(strict_types=1);

namespace Frontend\Services;

use Frontend\Config\FormConfig;

class BackendApiClient
{
    private string $baseUrl;
    private string $tenantSlug;
    private string $apiSecret;

    /**
     * @param string|null $baseUrl    Backend API base URL (default: BACKEND_API_URL)
     * @param string|null $tenantSlug Tenant slug (default: TENANT_SLUG env, else 'default')
     * @param string|null $apiSecret  Tenant API secret used to sign requests
     *                                (default: TENANT_API_SECRET env). Stays server-side;
     *                                it is never sent, only used to compute X-Signature.
     */
    public function __construct(?string $baseUrl = null, ?string $tenantSlug = null, ?string $apiSecret = null)
    {
        $this->baseUrl    = $baseUrl ?? FormConfig::getBackendUrl();
        $this->tenantSlug = $tenantSlug ?? (getenv('TENANT_SLUG') ?: 'default');
        $this->apiSecret  = $apiSecret ?? (getenv('TENANT_API_SECRET') ?: '');
    }

    /**
     * Build an endpoint URL carrying the tenant slug.
     *
     * The slug only tells the backend which tenant to look up; authorization
     * comes from the HMAC signature (see sign()), which only the holder of the
     * tenant's api_secret can produce.
     */
    private function endpoint(string $path): string
    {
        return $this->baseUrl . '/' . $path . '?tenant=' . urlencode($this->tenantSlug);
    }

    /**
     * HMAC-SHA256 signature as validated by backend App\Services\HmacValidator.
     */
    private function sign(string $message): string
    {
        return hash_hmac('sha256', $message, $this->apiSecret);
    }

    /**
     * Submit anmeldung to backend
     *
     * @param array|null $pdfConfig PDF configuration from frontend forms-config (optional)
     * @return array{success: bool, id?: int, error?: string}
     */
    public function submitAnmeldung(
        string $formKey,
        array $data,
        array $metadata,
        array $files = [],
        ?array $pdfConfig = null
    ): array {
        if ($this->apiSecret === '') {
            error_log('Backend API error: TENANT_API_SECRET is not configured');
            return ['success' => false, 'error' => 'Backend-Zugang nicht konfiguriert'];
        }

        $endpoint = $this->endpoint('submit.php');

        // Build payload
        $payload = [
            'form_key' => $formKey,
            'data' => $data,
            'metadata' => $metadata
        ];

        // Include PDF config if provided (frontend is single source of truth)
        if ($pdfConfig !== null) {
            $payload['pdf_config'] = $pdfConfig;
        }

        // Send request — signature covers the exact raw body that is sent
        $body = json_encode($payload);
        $ch = curl_init($endpoint);
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Signature: ' . $this->sign($body),
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log('Backend API error: ' . $error);
            return [
                'success' => false,
                'error' => 'Verbindung zum Backend fehlgeschlagen'
            ];
        }

        if ($httpCode !== 200 && $httpCode !== 201) {
            error_log("Backend API returned HTTP $httpCode: $response");
            $backendResult = json_decode($response, true);
            $errorMessage = (is_array($backendResult) && isset($backendResult['error']))
                ? $backendResult['error']
                : 'Backend-Fehler (HTTP ' . $httpCode . ')';
            return [
                'success' => false,
                'error' => $errorMessage
            ];
        }

        $result = json_decode($response, true);
        
        if (!is_array($result)) {
            error_log('Invalid JSON response from backend: ' . $response);
            return [
                'success' => false,
                'error' => 'Ungültige Antwort vom Backend'
            ];
        }

        // If submission was successful and we have files, upload them
        if (($result['success'] ?? false) && !empty($files) && isset($result['id'])) {
            $uploadResult = $this->uploadFiles($result['id'], $files);
            
            if (!$uploadResult['success']) {
                // Log file upload failure but don't fail the whole submission
                error_log('File upload failed for anmeldung #' . $result['id']);
                $result['file_upload_warning'] = $uploadResult['error'];
            }
        }

        return $result;
    }

    /**
     * Upload files for an anmeldung
     * 
     * @param array $files Array of $_FILES entries
     * @return array{success: bool, error?: string}
     */
    private function uploadFiles(int $anmeldungId, array $files): array
    {
        $endpoint = $this->endpoint('upload.php');

        foreach ($files as $fieldName => $file) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errorMsg = match ($file['error']) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Datei zu groß (Limit überschritten)',
                    UPLOAD_ERR_PARTIAL => 'Datei nur teilweise übertragen',
                    UPLOAD_ERR_NO_FILE => 'Keine Datei empfangen',
                    default => 'Upload-Fehler (Code ' . $file['error'] . ')',
                };
                error_log("File upload skipped for anmeldung #$anmeldungId, field $fieldName: $errorMsg");
                return ['success' => false, 'error' => $errorMsg];
            }

            $postData = [
                'anmeldung_id' => $anmeldungId,
                'fieldname' => $fieldName,
                'file' => new \CURLFile(
                    $file['tmp_name'],
                    $file['type'],
                    $file['name']
                )
            ];

            // Signature over "{anmeldung_id}:{fieldname}:{original_filename}" (see HmacValidator)
            $signature = $this->sign($anmeldungId . ':' . $fieldName . ':' . basename((string) $file['name']));

            $ch = curl_init($endpoint);
            
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => ['X-Signature: ' . $signature],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error || $httpCode !== 200) {
                error_log("File upload failed: HTTP $httpCode, Error: $error, Response: $response");
                return [
                    'success' => false,
                    'error' => 'Datei-Upload fehlgeschlagen'
                ];
            }
        }

        return ['success' => true];
    }

    /**
     * Fetch form configuration from backend API.
     *
     * Calls GET {baseUrl}/form-config.php?form={formKey}&tenant={tenantSlug}.
     * Returns the config array on success (HTTP 200, success=true),
     * or null on any failure (non-200, curl error, success=false).
     *
     * @param string $formKey    Form identifier (e.g. 'bs')
     * @param string $tenantSlug Tenant slug (e.g. 'default')
     * @return array|null Config array or null on failure
     */
    public function fetchFormConfig(string $formKey, string $tenantSlug): ?array
    {
        $url = $this->baseUrl . '/form-config.php?form=' . urlencode($formKey)
             . '&tenant=' . urlencode($tenantSlug);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log('fetchFormConfig curl error: ' . $curlError);
            return null;
        }

        if ($httpCode !== 200) {
            return null;
        }

        $result = json_decode((string) $response, true);
        return (($result['success'] ?? false) === true) ? ($result['config'] ?? null) : null;
    }

    /**
     * Fetch config, survey and theme of a form in one request (3.1).
     *
     * Calls GET {baseUrl}/form-config.php?form=…&tenant=…&with=survey and sends $etag (from an earlier
     * response) as If-None-Match, so the backend answers 304 while nothing changed.
     *
     * Status values:
     *   ok           → config, survey_json, theme_json (null = the backend has none, use the file), etag
     *   not_modified → the cached copy is still current
     *   not_found    → the form does not exist for this tenant (HTTP 404)
     *   denied       → unknown or inactive tenant (HTTP 401/403)
     *   error        → anything else: backend unreachable, 5xx, unreadable answer
     *
     * A backend from before 3.1 ignores "with=survey" and answers with the config only: that is "ok" with
     * survey_json/theme_json = null and no etag.
     *
     * @return array{status:string, config?:array<string,mixed>, survey_json?:?string, theme_json?:?string, etag?:?string}
     */
    public function fetchFormBundle(string $formKey, string $tenantSlug, ?string $etag = null): array
    {
        $url = $this->baseUrl . '/form-config.php?form=' . urlencode($formKey)
             . '&tenant=' . urlencode($tenantSlug) . '&with=survey';

        $headers = ['Accept: application/json'];
        if ($etag !== null && $etag !== '') {
            $headers[] = 'If-None-Match: "' . $etag . '"';
        }

        $response = $this->httpGet($url, $headers);

        if ($response['error'] !== null) {
            error_log('fetchFormBundle curl error: ' . $response['error']);
            return ['status' => 'error'];
        }
        if ($response['status'] === 304) {
            return ['status' => 'not_modified'];
        }
        if ($response['status'] === 404) {
            return ['status' => 'not_found'];
        }
        if ($response['status'] === 401 || $response['status'] === 403) {
            return ['status' => 'denied'];
        }
        if ($response['status'] !== 200) {
            return ['status' => 'error'];
        }

        $result = json_decode($response['body'], true);
        if (!is_array($result) || ($result['success'] ?? false) !== true || !is_array($result['config'] ?? null)) {
            return ['status' => 'error'];
        }

        $text = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : null;
        $etagHeader = isset($response['headers']['etag']) ? trim($response['headers']['etag'], " \t\"") : '';

        return [
            'status'      => 'ok',
            'config'      => $result['config'],
            'survey_json' => $text($result['survey_json'] ?? null),
            'theme_json'  => $text($result['theme_json'] ?? null),
            'etag'        => $etagHeader !== '' ? $etagHeader : null,
        ];
    }

    /**
     * One GET request. Separate from fetchFormBundle() so tests can replace the network.
     *
     * @param list<string> $headers
     * @return array{status:int, headers:array<string,string>, body:string, error:?string} header names lower-cased
     */
    protected function httpGet(string $url, array $headers): array
    {
        $responseHeaders = [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);

        $body      = curl_exec($ch);
        $status    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return [
            'status'  => $status,
            'headers' => $responseHeaders,
            'body'    => is_string($body) ? $body : '',
            'error'   => $curlError !== '' ? $curlError : null,
        ];
    }

    /**
     * Health check - test if backend is reachable.
     *
     * Uses a short 3-second timeout so a slow/unreachable backend
     * does not hold up the form page load indefinitely.
     *
     * @return array{status: 'ok'|'error', reason: string}
     */
    public function healthCheck(): array
    {
        $endpoint = $this->baseUrl . '/health.php';

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode === 0) {
            error_log('Backend health check failed: ' . $curlError);
            return ['status' => 'error', 'reason' => ''];
        }

        return $httpCode === 200
            ? ['status' => 'ok', 'reason' => '']
            : ['status' => 'error', 'reason' => ''];
    }
}