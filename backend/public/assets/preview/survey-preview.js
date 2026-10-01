// Survey preview: renders a survey in the SurveyJS runtime without saving anything.
// Loaded after survey.core, survey-js-ui and survey-handler-base.js (which registers placeholderExpression and
// provides the same dynamic placeholders as the real form).
(function () {
    'use strict';

    var cfg = window.previewConfig || {};

    class SurveyPreview extends SurveyHandlerBase {
        run() {
            var survey = new Survey.Model(cfg.survey);
            this._setupDynamicPlaceholders(survey);

            if (cfg.theme && Object.keys(cfg.theme).length > 0) {
                survey.applyTheme(cfg.theme);
            }

            // Nothing leaves the page: completing only shows a note.
            survey.onCompleting.add(function (sender, options) {
                options.allow = false;
                var note = document.getElementById('preview-note');
                if (note) {
                    note.textContent = cfg.completedNote || 'Vorschau: Das Formular wurde nicht abgeschickt, es wird nichts gespeichert.';
                    note.className = 'completed';
                    window.scrollTo(0, 0);
                }
            });

            survey.render(document.getElementById('surveyContainer'));
            return survey;
        }
    }

    window.__preview = new SurveyPreview(document).run();
})();
