<?php
// frontend/src/Services/AnmeldungService.php

declare(strict_types=1);

namespace Frontend\Services;

use Frontend\Config\FormConfig;

class AnmeldungService
{
    public function __construct(
        private BackendApiClient $apiClient,
        private ?EmailService $emailService = null
    ) {}

    /**
     * Process anmeldung submission
     *
     * @return array{success: bool, id?: int, error?: string, warnings?: array, pdf_download?: array}
     */
    public function processSubmission(
        string $formKey,
        array $surveyData,
        array $metadata = [],
        array $files = []
    ): array {
        // Validate form exists
        if (!FormConfig::exists($formKey)) {
            return [
                'success' => false,
                'error' => 'Unbekanntes Formular'
            ];
        }

        // Clean consent fields (don't save them)
        $surveyData = $this->cleanConsentFields($surveyData);

        // Extract metadata
        $name = $surveyData['Name'] ?? $surveyData['name'] ?? null;
        $email = $surveyData['email1'] ?? $surveyData['Email'] ?? $surveyData['email'] ?? null;

        // Prepare data for backend
        $submissionData = [
            'form_key' => $formKey,
            'name' => $name,
            'email' => $email,
            'data' => $surveyData,
            'metadata' => array_merge([
                'formular' => $formKey,
                'version' => $metadata['version'] ?? 'unknown',
                'submitted_at' => date('c'),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'ip_address' => $this->getClientIp()
            ], $metadata)
        ];

        $warnings = [];
        $pdfDownload = null;

        // Submit to backend (if configured)
        if (FormConfig::shouldSaveToDb($formKey)) {
            // Get PDF config from form config (frontend is single source of truth)
            $pdfConfig = FormConfig::getPdfConfig($formKey);

            $result = $this->apiClient->submitAnmeldung(
                $formKey,
                $submissionData['data'],
                $submissionData['metadata'],
                $files,
                $pdfConfig  // Send PDF config to backend
            );

            if (!$result['success']) {
                return $result;
            }

            $submissionId = $result['id'] ?? null;

            // Check for file upload warnings
            if (isset($result['file_upload_warning'])) {
                $warnings[] = $result['file_upload_warning'];
            }

            // Check for PDF download info
            if (isset($result['pdf_download'])) {
                $pdfDownload = $result['pdf_download'];
            }
        } else {
            // No DB save - just log it
            error_log("Form submission (no DB): $formKey");
            $submissionId = 0;
        }

        // Send notification email
        $notificationEmail = FormConfig::getNotificationEmail($formKey);

        if ($notificationEmail && $this->emailService) {
            try {
                $emailSent = $this->emailService->sendNotification(
                    $notificationEmail,
                    $formKey,
                    $surveyData
                );

                if (!$emailSent) {
                    $warnings[] = 'E-Mail-Benachrichtigung konnte nicht gesendet werden';
                }
            } catch (\Throwable $e) {
                error_log('Email notification failed: ' . $e->getMessage());
                $warnings[] = 'E-Mail-Benachrichtigung fehlgeschlagen';
            }
        }

        $response = [
            'success' => true,
            'id' => $submissionId ?? 0,
            'warnings' => $warnings
        ];

        // Add PDF download info if available
        if ($pdfDownload !== null) {
            $response['pdf_download'] = $pdfDownload;
        }

        return $response;
    }

    /**
     * Remove consent fields that shouldn't be stored
     */
    private function cleanConsentFields(array $data): array
    {
        return array_filter(
            $data,
            fn($key) => !str_starts_with($key, 'consent_'),
            ARRAY_FILTER_USE_KEY
        );
    }
    /**
     * Generate pre-fill link for given form and submitted data
     * to pre-fill fields specified in the prefill_fields in the forms-config.php.
     */

    public function generatePrefillLink(
        string $formKey,
        array $submittedData,
        ?string $pageUrl = null
    ): ?string {
        $config = FormConfig::get($formKey);
        $prefillFields = $config['prefill_fields'] ?? [];

        if (empty($prefillFields)) {
            return null; // Kein Pre-fill für dieses Formular
        }

        // Nur relevante Felder extrahieren
        $prefillData = array_intersect_key(
            $submittedData,
            array_flip($prefillFields)
        );

        if (empty($prefillData)) {
            return null;
        }

        // Als Base64 encoden (verschlüsseln bringt keine zusätzliche Sicherheit, da beim Abruf der URL
        // die Daten wieder sichtbar werden)
        $encoded = base64_encode(json_encode($prefillData));

        if ($pageUrl !== null) {
            // Use provided page URL as base (e.g. from wp_get_referer() in WordPress context)
            $parts = parse_url($pageUrl);
            $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
            if (!empty($parts['port'])) {
                $base .= ':' . $parts['port'];
            }
            $path = $parts['path'] ?? '/';
            return $base . $path . "?form={$formKey}&prefill={$encoded}";
        }

        // Fallback: build from SCRIPT_NAME (standard frontend context)
        $baseUrl = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'];
        $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
        return $baseUrl . $scriptPath . "/index.php?form={$formKey}&prefill={$encoded}";
    }

    /**
     * Get client IP address (behind proxy-aware)
     */
    private function getClientIp(): ?string
    {
        $headers = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                
                // X-Forwarded-For can contain multiple IPs
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }

                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return null;
    }
}