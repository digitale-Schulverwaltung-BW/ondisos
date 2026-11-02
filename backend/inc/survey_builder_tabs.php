<?php
declare(strict_types=1);

use App\Services\MessageService as M;

/**
 * The two tabs of the survey editor: the graphical builder ("Grafisch") and the JSON editor, which holds the text area
 * #survey-text (the form posts it; both tabs work on that one text).
 *
 * Needs: $builderConfig (inc/survey_builder.php), $editorText. Included by form_survey.php inside its form.
 */
?>
<?php $startJson = $builderConfig['startTab'] === 'json'; ?>
<ul class="nav nav-tabs mb-3" id="se-tabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link<?= $startJson ? '' : ' active' ?>" id="se-tab-graphic" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#se-pane-graphic"
                aria-controls="se-pane-graphic" aria-selected="<?= $startJson ? 'false' : 'true' ?>"><?= ff_e(M::get('builder.tab.graphic', 'Grafisch')) ?></button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link<?= $startJson ? ' active' : '' ?>" id="se-tab-json" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#se-pane-json"
                aria-controls="se-pane-json" aria-selected="<?= $startJson ? 'true' : 'false' ?>"><?= ff_e(M::get('builder.tab.json', 'JSON')) ?></button>
    </li>
</ul>
<div class="tab-content">
    <div class="tab-pane<?= $startJson ? '' : ' show active' ?>" id="se-pane-graphic" role="tabpanel" aria-labelledby="se-tab-graphic">
        <div id="sb-root" class="sb-root"><p class="text-muted"><?= ff_e(M::get('builder.loading', 'Der grafische Editor wird geladen …')) ?></p></div>
    </div>
    <div class="tab-pane<?= $startJson ? ' show active' : '' ?>" id="se-pane-json" role="tabpanel" aria-labelledby="se-tab-json">
        <div class="mb-2 d-flex justify-content-between align-items-end">
            <label class="form-label mb-0" for="survey-text"><?= ff_e(M::get('survey_editor.editor', 'Survey (JSON)')) ?></label>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="se-copy"><?= ff_e(M::get('survey_editor.copy', 'JSON kopieren')) ?></button>
        </div>
        <textarea id="survey-text" name="survey_text" class="form-control font-monospace" rows="26" spellcheck="false" wrap="off"
                  style="font-size: .8rem; white-space: pre;"><?= ff_e($editorText) ?></textarea>
        <div class="form-text mb-3"><?= ff_e(M::get('survey_editor.editor_help', 'Hier das JSON aus dem SurveyJS-Creator einfügen (siehe Anleitung unten). Beim Prüfen und Speichern wird nichts veröffentlicht.')) ?></div>
    </div>
</div>
