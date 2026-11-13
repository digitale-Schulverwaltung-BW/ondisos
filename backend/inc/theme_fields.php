<?php
declare(strict_types=1);

use App\Services\MessageService as M;

/**
 * The fields above the theme JSON (3.2.0b AP4): the usual settings. They read and write the text area #survey-text;
 * the script (assets/theme-editor/ui.js) fills them and unhides the card, without JavaScript only the JSON is there.
 * Included by theme_edit.php inside its form (the fields have no name: only the JSON is posted) and by the browser check.
 * Needs ff_e() (inc/form_fields.php).
 */

$tfTexts = [
    'readonly' => M::get('theme_editor.fields.readonly', 'Im JSON steht „{{value}}“; diese Angabe lässt sich hier nicht darstellen. Bitte im JSON bearbeiten.'),
    'legacy'   => M::get('theme_editor.fields.legacy', 'Aus dem älteren SurveyJS-Format übernommen; beim Ändern wird die Angabe auf das neue Format umgestellt.'),
    'contrast' => M::get('theme_editor.fields.contrast', 'Kontrast zu Weiß nur {{ratio}} : 1. Für Schrift auf der Markenfarbe (Buttons) und Links empfiehlt WCAG mindestens 4,5 : 1.'),
];
$tfDefault = M::get('theme_editor.fields.default', 'Standard von SurveyJS');
?>
<div class="card mb-3" id="tf-root" hidden>
    <div class="card-header"><h5 class="mb-0"><?= ff_e(M::get('theme_editor.fields.title', 'Einstellungen')) ?></h5></div>
    <div class="card-body">
        <p class="small text-muted"><?= ff_e(M::get('theme_editor.fields.help', 'Die Felder ändern das JSON darunter; alles, was hier nicht vorkommt, bleibt im JSON unverändert. Leere Felder bedeuten: Standard von SurveyJS.')) ?></p>
        <div class="alert alert-warning py-2 small" id="tf-unreadable" hidden><?= ff_e(M::get('theme_editor.fields.unreadable', 'Das JSON ist gerade nicht lesbar oder kein Theme-Objekt. Die Felder sind gesperrt, bis es wieder stimmt.')) ?></div>
        <div class="row g-3">
            <div class="col-md-6" data-field="brand">
                <label class="form-label" for="tf-brand"><?= ff_e(M::get('theme_editor.fields.brand', 'Markenfarbe')) ?></label>
                <div class="input-group">
                    <input type="color" class="form-control form-control-color flex-grow-0" style="width: 3.5rem;" id="tf-brand-picker" data-default="#19b394"
                           aria-label="<?= ff_e(M::get('theme_editor.fields.pick', 'Farbe wählen')) ?>">
                    <input type="text" class="form-control font-monospace" id="tf-brand" placeholder="<?= ff_e($tfDefault) ?> (#19b394)" spellcheck="false" autocomplete="off">
                </div>
                <div class="form-text"><?= ff_e(M::get('theme_editor.fields.brand_help', 'Buttons, Auswahl, Fokus und Links; helle und dunkle Töne leitet SurveyJS daraus ab. Hex (#00a3d7) oder rgb(…).')) ?></div>
                <div class="form-text text-warning-emphasis" id="tf-brand-contrast" hidden></div>
                <div class="tf-note form-text"></div>
            </div>
            <div class="col-md-6" data-field="background">
                <label class="form-label" for="tf-background"><?= ff_e(M::get('theme_editor.fields.background', 'Hintergrund')) ?></label>
                <div class="input-group">
                    <input type="color" class="form-control form-control-color flex-grow-0" style="width: 3.5rem;" id="tf-background-picker" data-default="#edf9f7"
                           aria-label="<?= ff_e(M::get('theme_editor.fields.pick', 'Farbe wählen')) ?>">
                    <input type="text" class="form-control font-monospace" id="tf-background" placeholder="<?= ff_e($tfDefault) ?> (#edf9f7)" spellcheck="false" autocomplete="off">
                </div>
                <div class="form-text"><?= ff_e(M::get('theme_editor.fields.background_help', 'Die Fläche hinter den Fragen.')) ?></div>
                <div class="tf-note form-text"></div>
            </div>
            <div class="col-md-6" data-field="titleBrand">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="tf-titleBrand">
                    <label class="form-check-label" for="tf-titleBrand"><?= ff_e(M::get('theme_editor.fields.title_brand', 'Formulartitel in der Markenfarbe')) ?></label>
                </div>
                <div class="tf-note form-text"></div>
            </div>
            <div class="col-md-6" data-field="cards">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="tf-cards">
                    <label class="form-check-label" for="tf-cards"><?= ff_e(M::get('theme_editor.fields.cards', 'Fragen als Karten (mit Schatten)')) ?></label>
                </div>
                <div class="tf-note form-text"></div>
            </div>
            <div class="col-md-6" data-field="font">
                <label class="form-label" for="tf-font-choice"><?= ff_e(M::get('theme_editor.fields.font', 'Schrift')) ?></label>
                <select class="form-select" id="tf-font-choice">
                    <option value=""><?= ff_e($tfDefault) ?></option>
                    <option value="opensans"><?= ff_e(M::get('theme_editor.fields.font_open_sans', 'Open Sans (mitgeliefert)')) ?></option>
                    <option value="system"><?= ff_e(M::get('theme_editor.fields.font_system', 'Systemschrift')) ?></option>
                    <option value="custom"><?= ff_e(M::get('theme_editor.fields.font_custom', 'Eigene Angabe …')) ?></option>
                </select>
                <input type="text" class="form-control mt-2" id="tf-font-custom" hidden spellcheck="false" autocomplete="off"
                       placeholder="Arial, sans-serif" aria-label="<?= ff_e(M::get('theme_editor.fields.font_custom', 'Eigene Angabe …')) ?>">
                <div class="form-text"><?= ff_e(M::get('theme_editor.fields.font_help', 'Ein Theme kann keine Schrift laden. Eigene Angaben wirken nur, wenn die Schrift auf dem Gerät der Besucher installiert ist.')) ?></div>
                <div class="tf-note form-text"></div>
            </div>
            <div class="col-md-6" data-field="fontSize">
                <label class="form-label" for="tf-fontSize"><?= ff_e(M::get('theme_editor.fields.font_size', 'Schriftgröße')) ?></label>
                <select class="form-select" id="tf-fontSize">
                    <option value=""><?= ff_e($tfDefault) ?> (16 px)</option>
                    <?php foreach ([14, 15, 16, 17, 18, 19, 20] as $size): ?><option value="<?= $size ?>"><?= $size ?> px</option><?php endforeach; ?>
                </select>
                <div class="tf-note form-text"></div>
            </div>
            <div class="col-md-6" data-field="radius">
                <label class="form-label" for="tf-radius"><?= ff_e(M::get('theme_editor.fields.radius', 'Ecken (Radius in px)')) ?></label>
                <input type="number" class="form-control" id="tf-radius" min="0" max="40" step="1" placeholder="<?= ff_e($tfDefault) ?>">
                <div class="form-text"><?= ff_e(M::get('theme_editor.fields.radius_help', 'Ein Wert für Karten, Buttons und Eingabefelder; 0 für eckig.')) ?></div>
                <div class="tf-note form-text"></div>
            </div>
            <div class="col-12" data-field="backgroundImage">
                <label class="form-label" for="tf-backgroundImage"><?= ff_e(M::get('theme_editor.fields.image', 'Hintergrundbild (Adresse)')) ?></label>
                <input type="text" class="form-control" id="tf-backgroundImage" spellcheck="false" autocomplete="off" placeholder="/bilder/hintergrund.jpg">
                <div class="form-text"><?= ff_e(M::get('theme_editor.fields.image_help', 'Am besten ein Bild auf dem eigenen Server. Ein Bild von einem fremden Server meldet „Prüfen“ als Datenschutz-Hinweis.')) ?></div>
                <div class="tf-note form-text"></div>
            </div>
            <div class="col-md-6" data-field="backgroundOpacity">
                <label class="form-label" for="tf-backgroundOpacity"><?= ff_e(M::get('theme_editor.fields.opacity', 'Deckkraft des Bildes (%)')) ?></label>
                <input type="number" class="form-control" id="tf-backgroundOpacity" min="0" max="100" step="5" placeholder="100">
                <div class="tf-note form-text"></div>
            </div>
            <div class="col-md-6" data-field="backgroundImageFit">
                <label class="form-label" for="tf-backgroundImageFit"><?= ff_e(M::get('theme_editor.fields.fit', 'Bild anpassen')) ?></label>
                <select class="form-select" id="tf-backgroundImageFit">
                    <option value=""><?= ff_e($tfDefault) ?></option>
                    <option value="cover"><?= ff_e(M::get('theme_editor.fields.fit_cover', 'Fläche füllen (cover)')) ?></option>
                    <option value="contain"><?= ff_e(M::get('theme_editor.fields.fit_contain', 'Ganz zeigen (contain)')) ?></option>
                    <option value="auto"><?= ff_e(M::get('theme_editor.fields.fit_auto', 'Originalgröße (auto)')) ?></option>
                </select>
                <div class="tf-note form-text"></div>
            </div>
        </div>
    </div>
</div>
<script type="application/json" id="tf-config"><?= json_encode(['texts' => $tfTexts], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?></script>
