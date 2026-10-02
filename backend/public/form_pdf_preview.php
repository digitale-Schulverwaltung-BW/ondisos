<?php
// public/form_pdf_preview.php
// PDF preview: renders the confirmation PDF of a form with sample answers (field names, 1.1.2000, 1, …). Nothing is saved.
// GET shows the saved configuration; POST (from the editor's "PDF-Vorschau" button) shows the values currently typed into the form.

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/form_editor.php';
require_once __DIR__ . '/../inc/form_fields.php';

use App\Config\TenantContext;
use App\Controllers\SurveyPreviewController;
use App\Forms\FormConfigFormMapper;
use App\Forms\PdfPreviewData;
use App\Models\Anmeldung;
use App\Services\MessageService as M;
use App\Services\PdfGeneratorService;
use App\Services\PdfLogoResolver;
use App\Services\PdfTemplateRenderer;

$formKey = is_string($_GET['form'] ?? null) ? $_GET['form'] : '';

/** Plain page for "no PDF today". */
$fail = static function (int $status, string $message, string $formKey) {
    http_response_code($status);
    require __DIR__ . '/../inc/header.php';
    echo '<div class="container mt-4"><div class="alert alert-warning">' . ff_e($message) . '</div>'
       . '<a href="form_edit.php?form=' . urlencode($formKey) . '" class="btn btn-secondary">' . ff_e(M::get('forms.back_to_form', '← Zurück zum Formular')) . '</a></div>';
    require __DIR__ . '/../inc/footer.php';
    exit;
};

if ($editorNoTenant) {
    $fail(400, M::get('forms.choose_tenant', 'Bitte oben rechts einen Tenant wählen: Formulare gehören immer zu genau einer Schule.'), $formKey);
}

$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($posted) {
    try {
        csrf_validate();
    } catch (\InvalidArgumentException $e) {
        $fail(403, M::get('forms.csrf_failed', 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.'), $formKey);
    }
}

$form = $editor->load($formKey);
if ($form === null) {
    $fail(404, M::get('forms.not_found', 'Dieses Formular gibt es nicht.'), $formKey);
}

$config = $form['config'];
if ($posted) {
    $submitted = FormConfigFormMapper::fromPost(is_array($_POST['cfg'] ?? null) ? $_POST['cfg'] : []);
    unset($submitted['pdf']['logo']); // a path read by the server: never from a request, the logo is decided below
    $config = FormConfigFormMapper::overlay($config, $submitted);
}

// Questions: the draft if there is one (what will be published next), else the published survey.
$survey = (new SurveyPreviewController($configs, $resources, $drafts))->load($formKey, SurveyPreviewController::SOURCE_DRAFT);
$decoded = $survey['status'] === 'ok' && is_string($survey['survey_json']) ? json_decode($survey['survey_json'], true) : null;
if (!is_array($decoded)) {
    $fail(404, M::get('forms.pdf_preview.no_survey', 'Für die PDF-Vorschau braucht das Formular eine Survey im Backend. Bitte zuerst im Survey-Editor eine Survey einfügen.'), $formKey);
}

$pdfConfig = (new PdfLogoResolver())->resolve(
    PdfPreviewData::config(is_array($config['pdf'] ?? null) ? $config['pdf'] : []) + ['logo' => $form['config']['pdf']['logo'] ?? null],
    $formKey,
    TenantContext::getTenantId(),
);

$anmeldung = new Anmeldung(
    id: 12345,
    formular: $formKey,
    formularVersion: is_string($config['version'] ?? null) ? $config['version'] : null,
    name: null,
    email: null,
    status: 'neu',
    data: PdfPreviewData::fromSurvey($decoded),
    createdAt: new \DateTimeImmutable(),
    updatedAt: null,
    deleted: false,
    deletedAt: null,
    pdfConfig: $pdfConfig,
);

try {
    $pdf = (new PdfGeneratorService(new PdfTemplateRenderer()))->generate($anmeldung, $pdfConfig, 'VORSCHAU');
} catch (\Throwable $e) {
    error_log('PDF preview failed: ' . $e->getMessage());
    $fail(500, M::get('forms.pdf_preview.failed', 'Die PDF-Vorschau konnte nicht erstellt werden.'), $formKey);
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="vorschau-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $formKey) . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $pdf;
