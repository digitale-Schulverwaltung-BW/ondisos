<?php
// frontend/src/Services/BackendApiClient.php

declare(strict_types=1);

namespace Frontend\Services;

use Frontend\Config\FormConfig;

class BackendApiClient
{
    private string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = $baseUrl ?? FormConfig::getBackendUrl();
    }

    /**
     * Submit anmeldung to backend
     * 
     * @return array{success: bool, id?: int, error?: string}
     */
    public function submitAnmeldung(
        string $formKey,
        array $data,
        array $metadata,
        array $files = []
    ): array {
        $endpoint = $this->baseUrl . '/submit.php';

        // Build payload
        $payload = [
            'form_key' => $formKey,
            'data' => $data,
            'metadata' => $metadata
        ];

        // Send request
        $ch = curl_init($endpoint);
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
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
            return [
                'success' => false,
                'error' => 'Backend-Fehler (HTTP ' . $httpCode . ')'
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
        $endpoint = $this->baseUrl . '/upload.php';

        foreach ($files as $fieldName => $file) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                continue;
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

            $ch = curl_init($endpoint);
            
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error || $httpCode !== 200) {
                error_log("File upload failed: HTTP $httpCode, Error: $error");
                return [
                    'success' => false,
                    'error' => 'Datei-Upload fehlgeschlagen'
                ];
            }
        }

        return ['success' => true];
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