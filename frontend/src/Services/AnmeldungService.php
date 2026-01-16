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
     * @return array{success: bool, id?: int, error?: string, warnings?: array}
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

        // Submit to backend (if configured)
        if (FormConfig::shouldSaveToDb($formKey)) {
            $result = $this->apiClient->submitAnmeldung(
                $formKey,
                $submissionData['data'],
                $submissionData['metadata'],
                $files
            );

            if (!$result['success']) {
                return $result;
            }

            $submissionId = $result['id'] ?? null;

            // Check for file upload warnings
            if (isset($result['file_upload_warning'])) {
                $warnings[] = $result['file_upload_warning'];
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

        return [
            'success' => true,
            'id' => $submissionId ?? 0,
            'warnings' => $warnings
        ];
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