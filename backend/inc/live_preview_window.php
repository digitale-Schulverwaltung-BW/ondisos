<?php
declare(strict_types=1);

use App\Services\MessageService as M;

/**
 * The live preview window (AP4a): toolbar, notice and the sandboxed frame. Rendered by form_preview_live.php and by the
 * browser check of the editor (backend/tools/survey-builder-harness).
 *
 * Needs: $liveForm (form key), $liveFrameUrl, $liveCheckUrl, $liveCsrf, $liveThemeMissing (bool), ff_e().
 * The script (assets/survey-builder/live-window.js) talks to the editor (window.opener) and the frame.
 */

$liveTexts = [
    'waiting'      => M::get('live_preview.status.waiting', 'Wartet auf den Editor …'),
    'connected'    => M::get('live_preview.status.connected', 'Verbunden'),
    'disconnected' => M::get('live_preview.status.disconnected', 'Keine Verbindung zum Editor. Die Vorschau zeigt den letzten Stand. Bitte im Editor erneut auf „Live-Vorschau“ klicken.'),
    'unreadable'   => M::get('live_preview.status.unreadable', 'Der Text im Editor ist gerade kein lesbares JSON. Die Vorschau zeigt den letzten guten Stand.'),
    'invalid'      => M::get('live_preview.status.invalid', 'Der aktuelle Stand wird nicht angezeigt, weil die Prüfung ihn ablehnt. Die Vorschau zeigt den letzten guten Stand:'),
    'render_error' => M::get('live_preview.status.render_error', 'SurveyJS kann diesen Stand nicht anzeigen. Die Vorschau zeigt den letzten guten Stand:'),
    'rate_limited' => M::get('live_preview.status.rate_limited', 'Zu viele Prüfungen in kurzer Zeit. Die Vorschau folgt in {{seconds}} Sekunden wieder.'),
    'session'      => M::get('live_preview.status.session', 'Die Sitzung ist abgelaufen. Bitte im Editor neu anmelden und die Vorschau neu öffnen.'),
    'failed'       => M::get('live_preview.status.failed', 'Die Prüfung ist fehlgeschlagen. Die Vorschau zeigt den letzten guten Stand.'),
    'hidden'       => M::get('live_preview.status.hidden', 'Das gewählte Element ist gerade ausgeblendet (Bedingung nicht erfüllt oder Antwort nicht gewählt). „Ausgeblendete zeigen“ blendet es ein.'),
    'missing'      => M::get('live_preview.status.missing', 'Das gewählte Element steht noch nicht in der Vorschau.'),
];
$liveConfig = [
    'form'     => $liveForm,
    'checkUrl' => $liveCheckUrl,
    'csrf'     => $liveCsrf,
    'texts'    => $liveTexts,
];
?>
<div class="lp-bar d-flex flex-wrap align-items-center gap-2 px-3 py-2 border-bottom bg-light">
    <h1 class="h6 mb-0"><?= ff_e(M::get('live_preview.title', 'Live-Vorschau')) ?> <code><?= ff_e($liveForm) ?></code></h1>
    <span id="lp-status" class="badge text-bg-secondary" role="status"><?= ff_e($liveTexts['waiting']) ?></span>
    <div class="ms-auto d-flex flex-wrap gap-2 align-items-center">
        <button type="button" class="btn btn-sm btn-outline-primary" id="lp-pick" aria-pressed="false"
                title="<?= ff_e(M::get('live_preview.pick_help', 'Ein Klick in die Vorschau wählt das Element im Editor, statt das Formular auszufüllen. Ohne diesen Schalter: Alt + Klick.')) ?>"><?= ff_e(M::get('live_preview.pick', 'Auswählen')) ?></button>
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch" id="lp-invisible">
            <label class="form-check-label small" for="lp-invisible"
                   title="<?= ff_e(M::get('live_preview.invisible_help', 'Zeigt auch Elemente, deren Bedingung gerade nicht erfüllt ist (blass).')) ?>"><?= ff_e(M::get('live_preview.invisible', 'Ausgeblendete zeigen')) ?></label>
        </div>
        <div class="btn-group" role="group" aria-label="<?= ff_e(M::get('preview.width', 'Breite')) ?>" id="lp-width">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-width="390"><?= ff_e(M::get('preview.phone', 'Handy')) ?></button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-width="820"><?= ff_e(M::get('preview.tablet', 'Tablet')) ?></button>
            <button type="button" class="btn btn-sm btn-outline-secondary active" data-width="100%"><?= ff_e(M::get('preview.desktop', 'Desktop')) ?></button>
        </div>
    </div>
</div>
<?php if ($liveThemeMissing): ?>
    <div class="alert alert-light border small m-2 py-1"><?= ff_e(M::get('preview.no_theme', 'Das Theme (Farben, Schrift) liegt als Datei im Frontend und fehlt in der Vorschau. Das Formular sieht für Besucher also etwas anders aus.')) ?></div>
<?php endif; ?>
<div id="lp-notice" class="alert alert-warning small m-2 py-2" role="alert" hidden></div>
<div class="lp-stage">
    <iframe id="lp-frame" title="<?= ff_e(M::get('live_preview.title', 'Live-Vorschau')) ?>" src="<?= ff_e($liveFrameUrl) ?>"></iframe>
</div>
<script type="application/json" id="live-config"><?= json_encode($liveConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) ?></script>
