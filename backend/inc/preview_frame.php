<?php
declare(strict_types=1);

use App\Services\MessageService as M;

/**
 * The document of the sandboxed preview frame (form_preview_frame.php; the browser check of the editor renders it too).
 *
 * Needs: $nonce, $message (?string: shown instead of the survey), $frameSurveyJson (?string, JSON safe for a script
 * element; null in the live preview), $frameThemeJson (string, same), $frameLive (bool), $e (HTML escaping).
 * Optional: $frameThemeLive (bool, theme preview of the theme editor: the survey is embedded, the theme arrives by message).
 * The page that includes this sends the sandbox CSP header.
 */

/** Fonts as data URIs: a sandboxed document has no origin and could not load them from the server. */
$font = static function (string $file): string {
    $path = __DIR__ . '/../public/assets/preview/fonts/' . $file;
    return is_file($path) ? 'data:font/woff2;base64,' . base64_encode((string)file_get_contents($path)) : '';
};

/** Asset URL with the file's modification time, so browsers fetch a changed file instead of a cached one. */
$asset = static function (string $file) use ($e): string {
    $mtime = @filemtime(__DIR__ . '/../public/assets/preview/' . $file);
    return $e('assets/preview/' . $file . ($mtime !== false ? '?v=' . $mtime : ''));
};

$frameThemeLive ??= false;

$jsonText = static fn (string $key, string $default): string => json_encode(M::get($key, $default), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $e(M::get('preview.title', 'Vorschau')) ?></title>
<?php if ($message === null): ?>
    <link href="<?= $asset('survey-core.min.css') ?>" rel="stylesheet">
<?php endif; ?>
    <style>
        @font-face { font-family: 'Open Sans'; font-weight: 400; font-style: normal; src: url('<?= $font('open-sans-v44-latin-regular.woff2') ?>') format('woff2'); }
        @font-face { font-family: 'Open Sans'; font-weight: 700; font-style: normal; src: url('<?= $font('open-sans-v44-latin-700.woff2') ?>') format('woff2'); }
        :root {
            --font-family: 'Open Sans', sans-serif !important;
        }

        body {
            font-family: var(--font-family);
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }

        #surveyContainer {
            margin: 0 auto;
            max-width: 900px;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        /*
         * Reset theme interference on SurveyJS dropdown filter input.
         * Some themes set padding:1%, width:98%, margin:0 0 30px on input[type="text"]
         * which inflates .sd-dropdown__filter-string-input (SurveyJS expects padding:0).
         * Selector specificity (0,2,0) beats typical theme rules (0,1,1).
         */
        #surveyContainer .sd-dropdown__filter-string-input {
            padding: 0 !important;
            width: 100% !important;
            margin: 0 !important;
            border: none !important;
            border-radius: 0 !important;
            background: transparent !important;
            line-height: 1.5 !important;
            box-sizing: content-box !important;
        }
        #preview-note { max-width: 900px; margin: 0 auto 12px; padding: 8px 14px; border-radius: 6px; background: #fff3cd; color: #664d03; font-size: 14px; }
        #preview-note.completed { background: #d1e7dd; color: #0f5132; }
        #preview-pages { max-width: 900px; margin: 0 auto 12px; display: flex; gap: 8px; align-items: center; }
        #preview-pages[hidden] { display: none; }
        #preview-pages button { flex: 0 0 auto; width: 40px; height: 36px; border: 1px solid #ccc; border-radius: 6px; background: white; font-size: 20px; line-height: 1; cursor: pointer; }
        #preview-pages button:disabled { opacity: .35; cursor: default; }
        #preview-pages select { flex: 1 1 auto; min-width: 0; height: 36px; padding: 0 8px; border: 1px solid #ccc; border-radius: 6px; background: white; font: inherit; font-size: 15px; }
        #preview-message { max-width: 900px; margin: 40px auto; padding: 16px; background: #f8d7da; color: #58151c; border-radius: 6px; }
<?php if ($frameLive): ?>
        /* live preview: the element chosen in the editor, the element under the mouse while picking */
        .lp-flash { outline: 3px solid #0d6efd !important; outline-offset: 4px; border-radius: 4px; transition: outline-color 1.5s ease-out; }
        .lp-flash.lp-fade { outline-color: transparent !important; }
        body.lp-picking #surveyContainer, body.lp-picking #surveyContainer * { cursor: pointer !important; }
        .lp-hover { outline: 2px dashed #0d6efd !important; outline-offset: 2px; background-color: rgba(13, 110, 253, .05) !important; }
        /* "show hidden": questions of an answer that is not chosen, faded like SurveyJS's hidden elements (.sd-element--invisible) */
        body.lp-show-invisible .sd-item:not(.sd-item--checked) + .sd-panel { opacity: .35; } /* SurveyJS puts the answer's panel right after its .sd-item */
<?php endif; ?>
    </style>
</head>
<body>
<?php if ($message !== null): ?>
    <div id="preview-message"><?= $e($message) ?></div>
<?php else: ?>
    <div id="preview-note"><?= $e($frameLive
        ? M::get('live_preview.frame_note', 'Live-Vorschau: zeigt den Stand im Editor, auch ungespeichert. Eingaben werden nicht gespeichert, „Abschicken" sendet nichts.')
        : ($frameThemeLive
            ? M::get('live_preview.theme_frame_note', 'Live-Vorschau des Themes: das veröffentlichte Formular mit dem Theme aus dem Editor, auch ungespeichert. Eingaben werden nicht gespeichert, „Abschicken" sendet nichts.')
            : M::get('preview.note', 'Vorschau: Eingaben werden nicht gespeichert, „Abschicken" sendet nichts.'))) ?></div>
    <nav id="preview-pages" hidden>
        <button type="button" id="preview-prev" title="<?= $e(M::get('preview.page_prev', 'Vorherige Seite (ohne Prüfung der Pflichtfelder)')) ?>">&lsaquo;</button>
        <select id="preview-page-select" aria-label="<?= $e(M::get('preview.page_select', 'Seite direkt aufrufen')) ?>"></select>
        <button type="button" id="preview-next" title="<?= $e(M::get('preview.page_next', 'Nächste Seite (ohne Prüfung der Pflichtfelder)')) ?>">&rsaquo;</button>
    </nav>
    <div id="surveyContainer"></div>

    <script nonce="<?= $e($nonce) ?>" src="<?= $asset('survey.core.min.js') ?>"></script>
    <script nonce="<?= $e($nonce) ?>" src="<?= $asset('survey-js-ui.min.js') ?>"></script>
    <script nonce="<?= $e($nonce) ?>" src="<?= $asset('i18n/german.min.js') ?>"></script>
    <script nonce="<?= $e($nonce) ?>" src="<?= $asset('survey-handler-base.js') ?>"></script>
    <script nonce="<?= $e($nonce) ?>">
        window.previewConfig = {
            live: <?= $frameLive ? 'true' : 'false' ?>,
            themeLive: <?= $frameThemeLive ? 'true' : 'false' ?>,
            survey: <?= $frameSurveyJson ?? 'null' ?>,
            theme: <?= $frameThemeJson ?>,
            completedNote: <?= $jsonText('preview.completed', 'Vorschau: Das Formular wurde nicht abgeschickt, es wird nichts gespeichert.') ?>,
            startPageLabel: <?= $jsonText('preview.start_page', 'Startseite') ?>
        };
    </script>
    <script nonce="<?= $e($nonce) ?>" src="<?= $asset('survey-preview.js') ?>"></script>
<?php endif; ?>
</body>
</html>
