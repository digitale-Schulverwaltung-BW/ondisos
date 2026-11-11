<?php
// public/form_preview_check.php
// Live preview of the survey editor (AP4a): may this unsaved text be rendered? Nothing is stored.
//
// POST (form-encoded): csrf_token, form, survey_text
//   → {"ok": true, "status": "ok"|"invalid", "errors": [{path, message}, …]}   at most SurveyPreviewController::MAX_REPORTED
//   → {"ok": false, "error": "method"|"csrf"|"not_found"|"rate_limited"}      with 405/403/404/429
// Called by the live preview window (form_preview_live.php, same origin, admin session). It forwards a text to the
// sandboxed frame only after "ok": the frame renders nothing the server has not accepted.

declare(strict_types=1);

// Own rate-limit bucket: one check per change must not use up the allowance for saving and publishing.
define('EDITOR_RATE_LIMIT_SCOPE', 'preview');

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/form_editor.php';

use App\Controllers\SurveyPreviewController;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

/** @param array<string,mixed> $body */
function preview_check_respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    preview_check_respond(405, ['ok' => false, 'error' => 'method']);
}

try {
    csrf_validate();
} catch (\InvalidArgumentException $e) {
    preview_check_respond(403, ['ok' => false, 'error' => 'csrf']);
}

// Nothing below writes to the session: release its lock, so the editor's other requests do not wait for this one.
session_write_close();

if ($editorNoTenant) {
    preview_check_respond(404, ['ok' => false, 'error' => 'not_found']);
}

$formKey = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
$text    = is_string($_POST['survey_text'] ?? null) ? $_POST['survey_text'] : '';

$verdict = (new SurveyPreviewController($configs, $resources, $drafts))->checkText($formKey, $text);
if ($verdict['status'] === 'not_found') {
    preview_check_respond(404, ['ok' => false, 'error' => 'not_found']);
}

preview_check_respond(200, ['ok' => true, 'status' => $verdict['status'], 'errors' => $verdict['errors']]);
