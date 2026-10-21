<?php
// frontend/public/api/signature.php
//
// Relay for the signature on a smartphone (3.2.0a): the form page (create, poll) and the phone page
// (info, submit) talk to this script, which forwards to the backend over the signed API.
// POST JSON {"action": …}; create and poll carry the CSRF token in the X-CSRF-Token header.

declare(strict_types=1);

require_once __DIR__ . '/../../inc/bootstrap.php';

use Frontend\Config\FormConfigLoader;
use Frontend\Config\SurveySource;
use Frontend\Services\BackendApiClient;
use Frontend\Services\SignatureRelay;
use Frontend\Utils\CsrfProtection;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$send = static function (array $result): never {
    http_response_code($result['code']);
    echo json_encode($result['body'], JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $send(['code' => 405, 'body' => ['ok' => false, 'error' => 'method']]);
}

$raw = (string)file_get_contents('php://input', false, null, 0, SignatureRelay::MAX_BODY_BYTES + 1);
$input = strlen($raw) <= SignatureRelay::MAX_BODY_BYTES ? json_decode($raw, true) : null;
if (!is_array($input)) {
    $send(['code' => 400, 'body' => ['ok' => false, 'error' => 'invalid_input']]);
}

// CSRF only where the form page calls (create, poll). Release the session right away: the form page
// polls every few seconds and must not block its own other requests on the session lock.
$csrfValid = false;
if (in_array($input['action'] ?? null, SignatureRelay::ACTIONS_NEEDING_CSRF, true)) {
    $csrfValid = CsrfProtection::validate((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    session_write_close();
}

$formTitle = static function (string $formKey): string {
    try {
        return FormConfigLoader::ensureWithSurvey($formKey) ? SignatureRelay::surveyTitle(SurveySource::survey($formKey)) : '';
    } catch (\RuntimeException) {
        return '';
    }
};

$relay = new SignatureRelay(new BackendApiClient(), $formTitle);
$send($relay->handle($input, $csrfValid, SignatureRelay::siblingUrl($_SERVER, 'sign.php')));
