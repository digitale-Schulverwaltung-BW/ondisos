<?php
// frontend/public/save.php

declare(strict_types=1);

//require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/bootstrap.php';

use Frontend\Services\AnmeldungService;
use Frontend\Services\BackendApiClient;
use Frontend\Services\EmailService;
use Frontend\Utils\CsrfProtection;
use Frontend\Config\FormConfig;

header('Content-Type: application/json; charset=utf-8');

try {
    // 1. Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method', 405);
    }

    // 2. CSRF validation
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!CsrfProtection::validate($csrfToken)) {
        throw new RuntimeException('CSRF validation failed', 403);
    }

    // 3. Get form key
    $formKey = $_REQUEST['form'] ?? '';
    
    if (empty($formKey) || !FormConfig::exists($formKey)) {
        throw new RuntimeException('Unbekanntes Formular', 400);
    }

    // 4. Get survey data
    if (empty($_POST['survey_data'])) {
        throw new RuntimeException('Keine Formulardaten empfangen', 400);
    }

    $surveyData = json_decode($_POST['survey_data'], true, 512, JSON_THROW_ON_ERROR);
    
    if (!is_array($surveyData)) {
        throw new RuntimeException('Ungültige Formulardaten', 400);
    }

    // 5. Get metadata
    $metadata = json_decode($_POST['meta'] ?? '{}', true) ?? [];

    // 6. Initialize services
    $apiClient = new BackendApiClient();
    $emailService = new EmailService();
    $anmeldungService = new AnmeldungService($apiClient, $emailService);

    // 7. Process submission
    $result = $anmeldungService->processSubmission(
        formKey: $formKey,
        surveyData: $surveyData,
        metadata: $metadata,
        files: $_FILES
    );

    // 8. Regenerate CSRF token for next submission
    if ($result['success']) {
        CsrfProtection::regenerate();
        // Generate prefill link
        $prefillLink = $anmeldungService->generatePrefillLink(
            $formKey,
            $surveyData
        );
        $result['prefill_link'] = $prefillLink;
    }

    // 9. Return result
    http_response_code($result['success'] ? 200 : 400);
    echo json_encode($result);

} catch (JsonException $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Ungültige JSON-Daten'
    ]);

} catch (RuntimeException $e) {
    http_response_code($e->getCode() ?: 400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);

} catch (Throwable $e) {
    error_log('Unexpected error in save.php: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Ein unerwarteter Fehler ist aufgetreten'
    ]);
}