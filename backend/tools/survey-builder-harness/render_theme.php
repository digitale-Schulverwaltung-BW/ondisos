<?php
declare(strict_types=1);

/**
 * Part of render.php (SB_THEME=1): the theme editor's live preview (3.2.0b AP3) as static pages. Uses the same partials
 * as the product: inc/live_preview_window.php (theme mode) and inc/preview_frame.php (theme mode).
 *
 * Expects from render.php: $surveyFile (here: the theme text), $outDir (with the assets link).
 */

use App\Services\MessageService as M;

$dir = rtrim($outDir, '/');
$themeText = is_file($surveyFile) ? (string)file_get_contents($surveyFile) : '{}';
$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// The frame: a published survey with a start page and two pages, the theme arrives by message.
$nonce = 'harness';
$message = null;
$frameSurveyJson = \App\Utils\JsonEmbed::encode((string)json_encode([
    'title' => 'Theme-Probe',
    'pages' => [
        ['name' => 'p1', 'elements' => [['type' => 'text', 'name' => 'vorname', 'title' => 'Vorname', 'isRequired' => true]]],
        ['name' => 'p2', 'elements' => [['type' => 'radiogroup', 'name' => 'farbe', 'title' => 'Farbe', 'choices' => ['rot', 'blau']]]],
    ],
]));
$frameThemeJson = '{}';
$frameLive = false;
$frameThemeLive = true;
ob_start();
require __DIR__ . '/../../inc/preview_frame.php';
file_put_contents($dir . '/frame.html', (string)ob_get_clean());

// The window in theme mode.
$liveMode = 'theme';
$liveForm = 'harness_theme.json';
$liveForms = [['key' => 'eins', 'has_survey' => true], ['key' => 'ohne', 'has_survey' => false], ['key' => 'zwei', 'has_survey' => true]];
$liveSelectedForm = 'eins';
$liveFrameBase = 'frame.html?form=';
$liveFrameUrl = $liveFrameBase . 'eins';
$liveCheckUrl = 'check';
$liveCsrf = 'harness-token';
$liveThemeMissing = false;
ob_start();
require __DIR__ . '/../../inc/live_preview_window.php';
$window = (string)ob_get_clean();
file_put_contents($dir . '/live.html', <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Live-Vorschau Theme (Harness)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="assets/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/survey-builder/live-window.css" rel="stylesheet">
</head>
<body class="lp-body">
{$window}
<script type="module" src="assets/survey-builder/live-window.js"></script>
</body>
</html>
HTML);

// A minimal theme editor: the fields, the text area and the button of theme_edit.php, with the page's scripts.
$cm = getenv('SB_NO_CODEMIRROR') === false && is_file(__DIR__ . '/../../public/assets/codemirror/survey-editor-cm.js') ? '<script src="assets/codemirror/survey-editor-cm.js"></script>' : '';
$label = $e(M::get('live_preview.open', 'Live-Vorschau'));
ob_start();
require __DIR__ . '/../../inc/theme_fields.php';
$fields = (string)ob_get_clean();
$text = $e($themeText);
file_put_contents($dir . '/index.html', <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Theme-Editor (Harness)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="assets/bootstrap/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-4 mb-5">
<form method="post" action="#submitted" id="survey-form">
    <button type="button" class="btn btn-sm btn-outline-primary" id="te-live-preview" hidden
            data-url="live.html" data-window="sb-theme-harness" data-form="harness_theme.json">{$label} ↗</button>
{$fields}
    <button type="button" class="btn btn-sm btn-outline-secondary" id="se-copy">JSON kopieren</button>
    <textarea id="survey-text" name="theme_text" class="form-control font-monospace" rows="20">{$text}</textarea>
</form>
</div>
<script type="application/json" id="survey-diagnostics">[]</script>
{$cm}
<script src="assets/survey-editor.js"></script>
<script type="module" src="assets/theme-editor.js"></script>
</body>
</html>
HTML);

echo "Wrote {$dir}/index.html (theme)\n";
