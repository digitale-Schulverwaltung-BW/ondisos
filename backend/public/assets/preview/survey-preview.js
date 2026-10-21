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
            this._setupPageNavigation(survey);
            return survey;
        }

        // Page bar above the survey (‹ page select ›): jumps to any page without validating required fields,
        // including the start page (firstPageIsStartPage). Fixed layout on purpose, so the buttons don't move with
        // the length of the page title.
        _setupPageNavigation(survey) {
            var bar = document.getElementById('preview-pages');
            var select = document.getElementById('preview-page-select');
            var prev = document.getElementById('preview-prev');
            var next = document.getElementById('preview-next');
            if (!bar || !select || !prev || !next) {
                return;
            }

            // Start page (if any) first, then the visible pages.
            var pages = function () {
                var list = survey.visiblePages.slice();
                if (survey.firstPageIsStartPage && survey.startPage) {
                    list.unshift(survey.startPage);
                }
                return list;
            };
            var currentIndex = function () {
                return pages().indexOf(survey.state === 'starting' ? survey.startPage : survey.currentPage);
            };

            var goTo = function (index) {
                var list = pages();
                if (index < 0 || index >= list.length) {
                    return;
                }
                if (list[index] === survey.startPage && survey.firstPageIsStartPage) {
                    survey.isStartedState = true;
                } else {
                    // survey.start() would validate the start page: leave it directly.
                    survey.isStartedState = false;
                    survey.currentPage = list[index];
                }
                refresh();
                window.scrollTo(0, 0);
            };

            var refresh = function () {
                var list = pages();
                var running = survey.state === 'running' || survey.state === 'starting';
                bar.hidden = list.length < 2 || !running;
                select.textContent = '';
                list.forEach(function (page, i) {
                    var option = document.createElement('option');
                    option.value = String(i);
                    var title = page.processedTitle || page.title
                        || (page === survey.startPage ? (cfg.startPageLabel || 'Startseite') : page.name);
                    option.textContent = (i + 1) + '. ' + title;
                    select.appendChild(option);
                });
                var current = currentIndex();
                select.value = String(current);
                prev.disabled = current <= 0;
                next.disabled = current < 0 || current >= list.length - 1;
            };

            select.addEventListener('change', function () { goTo(parseInt(select.value, 10)); });
            prev.addEventListener('click', function () { goTo(currentIndex() - 1); });
            next.addEventListener('click', function () { goTo(currentIndex() + 1); });

            survey.onCurrentPageChanged.add(refresh);
            survey.onPageVisibleChanged.add(refresh);
            survey.onStarted.add(refresh);
            survey.onComplete.add(refresh);
            refresh();
        }
    }

    window.__preview = new SurveyPreview(document).run();
})();
