<?php
declare(strict_types=1);

/**
 * Renders the editor tabs of form_survey.php into a static page, to try the graphical builder in a browser without
 * the database and the login (development and checks only, not part of the product):
 *
 *   php backend/tools/survey-builder-harness/render.php <survey.json> <output-dir> [report.json]
 *   python3 -m http.server --directory <output-dir>        then open /index.html
 *
 * The markup comes from the same partial the page uses (inc/survey_builder_tabs.php), the configuration from the same
 * function (inc/survey_builder.php); the scripts are the ones of the page, in the same order. <output-dir>/assets points
 * at backend/public/assets. An optional report file (the result of SurveyEditorController::check as JSON) shows findings.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../inc/form_fields.php';
require_once __DIR__ . '/../../inc/survey_builder.php';

[, $surveyFile, $outDir] = $argv + [null, null, null];
$reportFile = $argv[3] ?? null;
if (!is_string($surveyFile) || !is_string($outDir)) {
    fwrite(STDERR, "Usage: php render.php <survey.json> <output-dir> [report.json]\n");
    exit(1);
}

$editorText = is_file($surveyFile) ? (string)file_get_contents($surveyFile) : '';
$report = $reportFile !== null && is_file($reportFile) ? json_decode((string)file_get_contents($reportFile), true, 32, JSON_THROW_ON_ERROR) : null;
$view = ['live' => ['fields' => ['question1', 'Name']]];
$builderConfig = sb_config($view, $report);

if (!is_dir($outDir) && !mkdir($outDir, 0775, true)) {
    fwrite(STDERR, "Cannot create {$outDir}\n");
    exit(1);
}
$link = rtrim($outDir, '/') . '/assets';
if (!is_link($link)) {
    symlink(realpath(__DIR__ . '/../../public/assets'), $link);
}

ob_start();
require __DIR__ . '/../../inc/survey_builder_tabs.php';
$tabs = (string)ob_get_clean();

// SB_NO_CODEMIRROR=1 leaves the code editor out (the page then works with the plain text area)
$cm = getenv('SB_NO_CODEMIRROR') === false && is_file(__DIR__ . '/../../public/assets/codemirror/survey-editor-cm.js') ? '<script src="assets/codemirror/survey-editor-cm.js"></script>' : '';

file_put_contents(rtrim($outDir, '/') . '/index.html', <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Survey-Builder (Harness)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="assets/bootstrap/bootstrap.min.css" rel="stylesheet">
</head>
<body style="padding-top: 1rem">
<link href="assets/survey-builder/builder.css" rel="stylesheet">
<div class="container mt-4 mb-5" id="se-page">
<form method="post" action="#submitted" id="survey-form">
{$tabs}
<div class="d-flex flex-wrap gap-2 mb-4">
    <button type="submit" name="action" value="check" class="btn btn-outline-primary">Prüfen</button>
    <button type="submit" name="action" value="save_draft" class="btn btn-primary">Entwurf speichern</button>
</div>
</form>
</div>
{$cm}
<script src="assets/survey-editor.js"></script>
<script type="application/json" id="builder-config">
HTML . sb_json($builderConfig) . <<<HTML
</script>
<script type="module" src="assets/survey-builder/app.js"></script>
<script src="assets/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>
HTML);

echo "Wrote {$outDir}/index.html\n";
