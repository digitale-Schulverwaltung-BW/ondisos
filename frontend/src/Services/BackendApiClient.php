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

    // Failure reasons reported by fetchFormConfigResult() / checkTenant()
    public const FAIL_UNREACHABLE  = 'unreachable';  // DNS, connection refused, timeout, TLS
    public const FAIL_UNAUTHORIZED = 'unauthorized'; // 401: tenant slug unknown or inactive
    public const FAIL_NOT_FOUND    = 'not_found';    // 404: backend reachable, form unknown for the tenant
    public const FAIL_ERROR        = 'error';        // any other HTTP status or an unusable response

    /**
     * Backend API base URL this client talks to (for diagnostics).
     */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Fetch form configuration from backend API.
     *
     * Calls GET {baseUrl}/form-config.php?form={formKey}&tenant={tenantSlug}.
     * Returns the config array on success (HTTP 200, success=true),
     * or null on any failure. Use fetchFormConfigResult() to learn WHY it failed.
     *
     * @param string $formKey    Form identifier (e.g. 'bs')
     * @param string $tenantSlug Tenant slug (e.g. 'default')
     * @return array|null Config array or null on failure
     */
    public function fetchFormConfig(string $formKey, string $tenantSlug): ?array
    {
        return $this->fetchFormConfigResult($formKey, $tenantSlug)['config'];
    }

    /**
     * Like fetchFormConfig(), but says why a fetch failed.
     *
     * "Unreachable backend" and "unknown form" need different reactions (fix the URL vs. fix the form key),
     * which a bare null cannot express.
     *
     * @return array{config: ?array, reason: ?string, http_code: int, detail: string}
     *         reason is null on success, otherwise one of the FAIL_* constants; detail is a short
     *         technical explanation for logs/admins (never contains secrets).
     */
    public function fetchFormConfigResult(string $formKey, string $tenantSlug): array
    {
        $url = $this->baseUrl . '/form-config.php?form=' . urlencode($formKey)
             . '&tenant=' . urlencode($tenantSlug);

        $response = $this->httpGet($url, 5);
        $code     = $response['code'];

        if ($response['error'] !== '' || $code === 0) {
            $detail = $response['error'] !== '' ? $response['error'] : 'no response';
            error_log('fetchFormConfig curl error: ' . $detail);
            return ['config' => null, 'reason' => self::FAIL_UNREACHABLE, 'http_code' => 0, 'detail' => $detail];
        }

        if ($code === 401) {
            return ['config' => null, 'reason' => self::FAIL_UNAUTHORIZED, 'http_code' => $code,
                    'detail' => 'tenant "' . $tenantSlug . '" is unknown or inactive'];
        }

        if ($code === 404) {
            return ['config' => null, 'reason' => self::FAIL_NOT_FOUND, 'http_code' => $code,
                    'detail' => 'form "' . $formKey . '" does not exist for tenant "' . $tenantSlug . '"'];
        }

        if ($code !== 200) {
            return ['config' => null, 'reason' => self::FAIL_ERROR, 'http_code' => $code, 'detail' => 'HTTP ' . $code];
        }

        $result = json_decode($response['body'], true);
        if (!is_array($result) || ($result['success'] ?? false) !== true || !is_array($result['config'] ?? null)) {
            return ['config' => null, 'reason' => self::FAIL_ERROR, 'http_code' => $code,
                    'detail' => 'unexpected response (is the Backend API URL pointing at the ondisos API?)'];
        }

        return ['config' => $result['config'], 'reason' => null, 'http_code' => $code, 'detail' => ''];
    }

    /**
     * Is the tenant slug accepted by the backend? (Probes form-config.php with a form that cannot exist:
     * 404 = tenant known, 401 = tenant unknown/inactive.)
     *
     * @return array{ok: bool, reason: ?string, detail: string}
     */
    public function checkTenant(string $tenantSlug): array
    {
        $result = $this->fetchFormConfigResult('__probe__', $tenantSlug);

        if ($result['reason'] === self::FAIL_NOT_FOUND || $result['reason'] === null) {
            return ['ok' => true, 'reason' => null, 'detail' => ''];
        }

        return ['ok' => false, 'reason' => $result['reason'], 'detail' => $result['detail']];
    }

    /**
     * Which forms does the tenant have? Signed request (HMAC over "forms:<slug>" with the tenant secret), so it also
     * proves that the configured secret matches the tenant. Used by the WordPress plugin's connection status.
     *
     * reason: null on success; FAIL_UNAUTHORIZED = signature rejected (wrong secret, unknown/inactive tenant),
     * FAIL_UNREACHABLE, FAIL_NOT_FOUND = backend older than 3.1 (no such endpoint), FAIL_ERROR otherwise.
     *
     * @return array{ok: bool, reason: ?string, detail: string, forms: list<string>}
     */
    public function fetchTenantForms(string $tenantSlug): array
    {
        $url = $this->baseUrl . '/forms.php?tenant=' . urlencode($tenantSlug);

        if ($this->apiSecret === '') {
            return ['ok' => false, 'reason' => self::FAIL_UNAUTHORIZED, 'detail' => 'no tenant API secret configured', 'forms' => []];
        }

        $response = $this->httpRequest($url, 5, [
            'Accept: application/json',
            'X-Signature: ' . $this->sign('forms:' . $tenantSlug),
        ]);
        $code = $response['code'];

        if ($response['error'] !== '' || $code === 0) {
            return ['ok' => false, 'reason' => self::FAIL_UNREACHABLE, 'detail' => $response['error'] !== '' ? $response['error'] : 'no response', 'forms' => []];
        }
        if ($code === 401 || $code === 403) {
            return ['ok' => false, 'reason' => self::FAIL_UNAUTHORIZED, 'detail' => 'signature rejected: the tenant API secret does not match the tenant, or the tenant is unknown/inactive', 'forms' => []];
        }
        if ($code === 404) {
            return ['ok' => false, 'reason' => self::FAIL_NOT_FOUND, 'detail' => 'the backend has no forms.php (older than 3.1)', 'forms' => []];
        }
        if ($code !== 200) {
            return ['ok' => false, 'reason' => self::FAIL_ERROR, 'detail' => 'HTTP ' . $code, 'forms' => []];
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || ($data['success'] ?? false) !== true || !is_array($data['forms'] ?? null)) {
            return ['ok' => false, 'reason' => self::FAIL_ERROR, 'detail' => 'unexpected response', 'forms' => []];
        }

        return ['ok' => true, 'reason' => null, 'detail' => '', 'forms' => array_values(array_filter($data['forms'], 'is_string'))];
    }

    /**
     * Perform a GET request. Isolated so tests can replace the network.
     *
     * @return array{body: string, code: int, error: string} code 0 and a non-empty error when no HTTP response arrived
     */
    protected function httpGet(string $url, int $timeoutSeconds): array
    {
        $r = $this->httpRequest($url, $timeoutSeconds, ['Accept: application/json']);

        return ['body' => $r['body'], 'code' => $r['code'], 'error' => $r['error']];
    }

    /**
     * One GET request with custom request headers; also returns the response headers (names lower-cased).
     * The only place that talks curl; tests replace httpGet() or this method.
     *
     * @param list<string> $headers
     * @return array{body: string, code: int, error: string, headers: array<string,string>}
     */
    protected function httpRequest(string $url, int $timeoutSeconds, array $headers): array
    {
        $responseHeaders = [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);

        $body  = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return ['body' => is_string($body) ? $body : '', 'code' => $code, 'error' => $error, 'headers' => $responseHeaders];
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
     * Failures also carry reason (one of the FAIL_* constants), http_code and detail, like fetchFormConfigResult().
     *
     * @return array{status:string, config?:array<string,mixed>, survey_json?:?string, theme_json?:?string, etag?:?string, reason?:string, http_code?:int, detail?:string}
     */
    public function fetchFormBundle(string $formKey, string $tenantSlug, ?string $etag = null): array
    {
        $url = $this->baseUrl . '/form-config.php?form=' . urlencode($formKey)
             . '&tenant=' . urlencode($tenantSlug) . '&with=survey';

        $headers = ['Accept: application/json'];
        if ($etag !== null && $etag !== '') {
            $headers[] = 'If-None-Match: "' . $etag . '"';
        }

        $response = $this->httpRequest($url, 5, $headers);
        $code     = $response['code'];

        if ($response['error'] !== '' || $code === 0) {
            $detail = $response['error'] !== '' ? $response['error'] : 'no response';
            error_log('fetchFormBundle curl error: ' . $detail);
            return ['status' => 'error', 'reason' => self::FAIL_UNREACHABLE, 'http_code' => 0, 'detail' => $detail];
        }
        if ($code === 304) {
            return ['status' => 'not_modified'];
        }
        if ($code === 404) {
            return ['status' => 'not_found', 'reason' => self::FAIL_NOT_FOUND, 'http_code' => $code,
                    'detail' => 'form "' . $formKey . '" does not exist for tenant "' . $tenantSlug . '"'];
        }
        if ($code === 401 || $code === 403) {
            return ['status' => 'denied', 'reason' => self::FAIL_UNAUTHORIZED, 'http_code' => $code,
                    'detail' => 'tenant "' . $tenantSlug . '" is unknown or inactive'];
        }
        if ($code !== 200) {
            return ['status' => 'error', 'reason' => self::FAIL_ERROR, 'http_code' => $code, 'detail' => 'HTTP ' . $code];
        }

        $result = json_decode($response['body'], true);
        if (!is_array($result) || ($result['success'] ?? false) !== true || !is_array($result['config'] ?? null)) {
            return ['status' => 'error', 'reason' => self::FAIL_ERROR, 'http_code' => $code,
                    'detail' => 'unexpected response (is the Backend API URL pointing at the ondisos API?)'];
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
     * Health check - test if backend is reachable.
     *
     * Uses a short 3-second timeout so a slow/unreachable backend
     * does not hold up the form page load indefinitely.
     *
     * @return array{status: 'ok'|'error', reason: string}
     */
    public function healthCheck(): array
    {
        $response = $this->httpGet($this->baseUrl . '/health.php', 3);

        if ($response['error'] !== '' || $response['code'] === 0) {
            $reason = $response['error'] !== '' ? $response['error'] : 'no response';
            error_log('Backend health check failed: ' . $reason);
            return ['status' => 'error', 'reason' => $reason];
        }

        return $response['code'] === 200
            ? ['status' => 'ok', 'reason' => '']
            : ['status' => 'error', 'reason' => 'HTTP ' . $response['code']];
    }
}