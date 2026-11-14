<?php
// frontend/src/Services/AnmeldungService.php

declare(strict_types=1);

namespace Frontend\Services;

use Frontend\Config\FormConfig;
use Frontend\Utils\ClientIp;
use Frontend\Utils\ConsentCheck;

class AnmeldungService
{
    public function __construct(
        private BackendApiClient $apiClient,
        private ?EmailService $emailService = null
    ) {}

    /**
     * Process anmeldung submission
     *
     * @param array<string,mixed>|null $survey published survey; when given, required consents are checked
     *                                        against it (ConsentCheck). Null skips the check (survey not loadable).
     * @return array{success: bool, id?: int, error?: string, warnings?: array, pdf_download?: array}
     */
    public function processSubmission(
        string $formKey,
        array $surveyData,
        array $metadata = [],
        array $files = [],
        ?array $survey = null
    ): array {
        // Validate form exists
        if (!FormConfig::exists($formKey)) {
            return [
                'success' => false,
                'error' => 'Unbekanntes Formular'
            ];
        }

        // Fail closed: a form that is neither stored (db=false) nor mailed (no valid notify_email) would
        // discard the data and still report success. Refuse it and say so in the log.
        if (FormConfig::discardsSubmissions($formKey)) {
            error_log("Submission refused for form '{$formKey}': db is false and no valid notify_email is configured, the data would be discarded");

            return [
                'success' => false,
                'error'   => MessageService::withContact('errors.form_unavailable'),
            ];
        }

        // Consent: SurveyJS checks "required" only in the browser; a direct POST could leave it out
        if ($survey !== null) {
            $missing = ConsentCheck::missing($survey, $surveyData);
            if ($missing !== []) {
                error_log("Submission refused for form '{$formKey}': required consent missing (" . implode(', ', $missing) . ')');

                return [
                    'success' => false,
                    'error'   => MessageService::get('validation.consent_required', 'Bitte akzeptieren Sie die Bedingungen'),
                ];
            }
        }

        // Keep which consents were given as proof (internal field, hidden in list, export and mail);
        // the consent answers themselves are not stored
        $given = ConsentCheck::given($surveyData);
        $surveyData = $this->cleanConsentFields($surveyData);
        unset($surveyData[ConsentCheck::RECORD_FIELD]); // only the server sets it
        if ($given !== []) {
            $surveyData[ConsentCheck::RECORD_FIELD] = $given;
        }

        // Inject submission date if not already set (server-side, tamper-safe)
        if (empty($surveyData['Anmeldung am'])) {
            $surveyData['Anmeldung am'] = date('d.m.Y');
        }

        // Extract metadata
        $name = $surveyData['Name'] ?? $surveyData['name'] ?? null;
        $email = $surveyData['email1'] ?? $surveyData['Email'] ?? $surveyData['email'] ?? null;

        // Prepare data for backend
        $submissionData = [
            'form_key' => $formKey,
            'name' => $name,
            'email' => $email,
            'data' => $surveyData,
            // Serverseitige Werte zuletzt: der Client darf sie nicht überschreiben
            'metadata' => array_merge($metadata, [
                'formular' => $formKey,
                'version' => $metadata['version'] ?? 'unknown',
                'submitted_at' => date('c'),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'ip_address' => $this->getClientIp()
            ])
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
                $emailConfig = FormConfig::get($formKey)['email'] ?? [];
                $introTemplate = $emailConfig['intro_template'] ?? null;

                $emailSent = $this->emailService->sendNotification(
                    $notificationEmail,
                    $formKey,
                    $surveyData,
                    $introTemplate
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

        // In the fragment (#prefill=…), not in the query: browsers never send the fragment to a server, so the
        // data stays out of access logs, proxies and analytics. Whoever has the link can still read it (the link is
        // meant to be bookmarked); encrypting would not change that. base64url: no "+" "/" "=" to escape.
        $fragment = '#prefill=' . self::base64UrlEncode((string)json_encode($prefillData));

        if ($pageUrl !== null) {
            // Use provided page URL as base (e.g. from wp_get_referer() in WordPress context)
            $parts = parse_url($pageUrl);
            $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
            if (!empty($parts['port'])) {
                $base .= ':' . $parts['port'];
            }
            $path = $parts['path'] ?? '/';
            return $base . $path . '?form=' . rawurlencode($formKey) . $fragment;
        }

        // Fallback: build from SCRIPT_NAME (standard frontend context)
        $baseUrl = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'];
        $scriptPath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        return $baseUrl . $scriptPath . '/index.php?form=' . rawurlencode($formKey) . $fragment;
    }

    /** base64url without padding (RFC 4648 §5); survey-handler-base.js also reads plain base64 of older links. */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Client IP address; X-Forwarded-For counts only behind a proxy listed in TRUSTED_PROXIES
     */
    private function getClientIp(): ?string
    {
        $ip = ClientIp::get();

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }
}
