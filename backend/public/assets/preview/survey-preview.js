// Survey preview: renders a survey in the SurveyJS runtime without saving anything.
// Loaded after survey.core, survey-js-ui and survey-handler-base.js (which registers placeholderExpression and
// provides the same dynamic placeholders as the real form).
//
// Three modes (window.previewConfig.live / .themeLive):
//   - stored survey (form_preview.php): the survey is embedded in the page;
//   - live preview (AP4a, form_preview_live.php): the survey comes from the surrounding window by postMessage, after the
//     server accepted it. Every new text rebuilds the survey; page and answers stay. The window can ask to show an
//     element, and a click while picking (or Alt + click) tells the window which element it was: kind and name, never
//     a path (the editor finds the path itself);
//   - theme preview (3.2.0b AP3, theme_preview_live.php): the stored survey is embedded, the theme comes from the window
//     by postMessage ("theme"), after the server accepted it. Every new theme rebuilds the survey; page and answers stay.
// The frame is sandboxed (opaque origin): it only accepts messages from its parent and only sends kinds and names.
(function () {
    'use strict';

    var cfg = window.previewConfig || {};
    var PROTOCOL = 'ondisos-live-preview';
    var VERSION = 1;

    class SurveyPreview extends SurveyHandlerBase {
        constructor(root) {
            super(root);
            this.survey = null;
            this.container = document.getElementById('surveyContainer');
            this.showInvisible = false;
            this.picking = false;
            this.hovered = null;
            this._refreshPages = function () {};
            this.json = null;
        }

        /** Builds the survey from a JSON object; with keep = true, page, answers and scroll position stay. */
        build(json, keep) {
            var old = this.survey;
            this.json = json;
            var survey = new Survey.Model(json);
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

            survey.showInvisibleElements = this.showInvisible;
            var scroll = window.scrollY;
            if (keep && old) {
                survey.data = old.data;
                if (old.firstPageIsStartPage && old.state === 'starting' && survey.firstPageIsStartPage) {
                    survey.isStartedState = true;
                } else if (old.currentPage) {
                    // the same page by name, else the same position
                    var byName = survey.getPageByName(old.currentPage.name);
                    var page = byName && survey.visiblePages.indexOf(byName) > -1 ? byName : survey.visiblePages[Math.min(old.currentPageNo, survey.visiblePages.length - 1)];
                    if (survey.firstPageIsStartPage) { survey.isStartedState = false; }
                    if (page) { survey.currentPage = page; }
                }
            }

            var self = this;
            survey.onValueChanged.add(function () { self._showChoicePanels(survey); }); // a choice resets its answers' panels
            this._showChoicePanels(survey);
            survey.render(this.container);
            this.survey = survey;
            if (old) { old.dispose(); }
            this._watchPages(survey);
            if (keep) { window.scrollTo(0, scroll); }
            return survey;
        }

        // Page bar above the survey (‹ page select ›): jumps to any page without validating required fields,
        // including the start page (firstPageIsStartPage). Fixed layout on purpose, so the buttons don't move with
        // the length of the page title. The controls are wired once; each new survey hooks its events in _watchPages.
        _setupPageNavigation() {
            var self = this;
            var bar = document.getElementById('preview-pages');
            var select = document.getElementById('preview-page-select');
            var prev = document.getElementById('preview-prev');
            var next = document.getElementById('preview-next');
            if (!bar || !select || !prev || !next) {
                return;
            }

            // Start page (if any) first, then the visible pages.
            var pages = function () {
                var survey = self.survey;
                var list = survey.visiblePages.slice();
                if (survey.firstPageIsStartPage && survey.startPage) {
                    list.unshift(survey.startPage);
                }
                return list;
            };
            var currentIndex = function () {
                var survey = self.survey;
                return pages().indexOf(survey.state === 'starting' ? survey.startPage : survey.currentPage);
            };

            var goTo = function (index) {
                var list = pages();
                if (index < 0 || index >= list.length) {
                    return;
                }
                self._showPage(list[index]);
                refresh();
                window.scrollTo(0, 0);
            };

            var refresh = function () {
                var survey = self.survey;
                if (!survey) { return; }
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
            this._refreshPages = refresh;
        }

        _watchPages(survey) {
            var refresh = this._refreshPages;
            survey.onCurrentPageChanged.add(refresh);
            survey.onPageVisibleChanged.add(refresh);
            survey.onStarted.add(refresh);
            survey.onComplete.add(refresh);
            refresh();
        }

        /** Goes to a page without validating anything (survey.start() would validate the start page). */
        _showPage(page) {
            var survey = this.survey;
            if (page === survey.startPage && survey.firstPageIsStartPage) {
                survey.isStartedState = true;
            } else {
                survey.isStartedState = false;
                survey.currentPage = page;
            }
        }

        // ---- live preview ----------------------------------------------------------------------------------------

        startLive() {
            var self = this;
            window.addEventListener('message', function (event) {
                if (event.source !== window.parent || !event.data || event.data.ondisos !== PROTOCOL || event.data.v !== VERSION) {
                    return;
                }
                self._receive(event.data);
            });

            // Picking: a click (in picking mode, or Alt + click) selects instead of filling in.
            var picks = function (event) { return self.picking || event.altKey; };
            var swallow = function (event) {
                if (!picks(event) || !self.container.contains(event.target)) { return; }
                event.preventDefault();
                event.stopPropagation();
            };
            document.addEventListener('pointerdown', swallow, true);
            document.addEventListener('mousedown', swallow, true);
            document.addEventListener('click', function (event) {
                if (!picks(event) || !self.container.contains(event.target)) { return; }
                event.preventDefault();
                event.stopPropagation();
                var hit = self.targetAt(event.target);
                if (hit) {
                    self._flash(hit.element);
                    self._send('pick', { target: hit.target });
                }
            }, true);
            document.addEventListener('mouseover', function (event) {
                self._hover(self.picking || event.altKey ? event.target : null);
            }, true);
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && self.picking) { self._send('pick-cancel', {}); }
            });

            this._send('ready', {});
        }

        /** Theme preview: only "theme" messages from the parent; no picking, no other options. */
        startThemeLive() {
            var self = this;
            window.addEventListener('message', function (event) {
                if (event.source !== window.parent || !event.data || event.data.ondisos !== PROTOCOL || event.data.v !== VERSION) {
                    return;
                }
                if (event.data.type === 'theme') { self._receive(event.data); }
            });
            this._send('ready', {});
        }

        _send(type, payload) {
            var message = Object.assign({ ondisos: PROTOCOL, v: VERSION, type: type }, payload);
            // The parent is the live preview window of the admin (frame-ancestors 'self'); this frame has no origin of its
            // own to name it by, and the messages carry nothing but kinds and names of the survey.
            window.parent.postMessage(message, '*');
        }

        _receive(data) {
            if (data.type === 'render' && typeof data.text === 'string') {
                var json;
                try {
                    json = JSON.parse(data.text);
                    this.build(json, true);
                } catch (e) {
                    this._send('rendered', { ok: false, error: String(e && e.message ? e.message : e).slice(0, 300) });
                    return;
                }
                this._send('rendered', { ok: true, seq: data.seq });
            } else if (data.type === 'theme' && typeof data.text === 'string') {
                try {
                    var theme = JSON.parse(data.text);
                    if (!theme || typeof theme !== 'object' || Array.isArray(theme)) { throw new Error('Das Theme ist kein JSON-Objekt'); }
                    cfg.theme = theme;
                    // A new model instead of applyTheme() on the old one: variables the new theme leaves out must not stay.
                    if (this.json) { this.build(this.json, true); }
                } catch (e) {
                    this._send('rendered', { ok: false, error: String(e && e.message ? e.message : e).slice(0, 300) });
                    return;
                }
                this._send('rendered', { ok: true, seq: data.seq });
            } else if (data.type === 'show' && data.target) {
                var self = this;
                this.show(data.target, function (result) { self._send('shown', result); });
            } else if (data.type === 'options') {
                this.picking = Boolean(data.pick);
                document.body.classList.toggle('lp-picking', this.picking);
                if (!this.picking) { this._hover(null); }
                if (Boolean(data.invisible) !== this.showInvisible) {
                    this.showInvisible = Boolean(data.invisible);
                    document.body.classList.toggle('lp-show-invisible', this.showInvisible);
                    if (this.survey) {
                        this.survey.showInvisibleElements = this.showInvisible;
                        this._showChoicePanels(this.survey);
                    }
                }
            }
        }

        /**
         * Questions in answer options (choices[].elements) show only while their answer is chosen; SurveyJS decides that
         * per answer (setIsPanelShowing), showInvisibleElements does not reach it (checked against SurveyJS 3.2.0; after an
         * update of SurveyJS check.mjs, section "live", shows whether this still holds). With "show hidden" every answer shows
         * its questions (faded by CSS while the answer is not chosen); without, SurveyJS's own rule applies again.
         */
        _showChoicePanels(survey) {
            var on = this.showInvisible;
            this.questionsOf(survey).forEach(function (q) {
                if (typeof q.supportElementsInChoice !== 'function' || !q.supportElementsInChoice() || !Array.isArray(q.choices)) { return; }
                q.choices.forEach(function (choice) {
                    if (!choice || !choice.supportElements || !choice.hasElements) { return; }
                    if (!on) { q.updateItemIsPanelShowing(choice); return; }
                    choice.setIsPanelShowing(true);
                    // SurveyJS builds the rows of an answer's panel when it is first shown by choosing the answer;
                    // shown this way, build them and render again (else the panel stays empty).
                    if (typeof choice.panelFirstRendering === 'function') { choice.panelFirstRendering(); }
                    choice.renderedIsPanelShowing = true;
                });
            });
        }

        /** Maps of the current survey: DOM id → question / panel. */
        _byId() {
            var survey = this.survey;
            var questions = {};
            var panels = {};
            this.questionsOf(survey).forEach(function (q) { questions[q.id] = q; });
            survey.getAllPanels(false, true).forEach(function (p) { panels[p.id] = p; });
            return { questions: questions, panels: panels };
        }

        /**
         * The element of the survey at a DOM node: the nearest question, panel or answer option around it, else the page.
         * @returns {{target: object, element: Element}|null}
         */
        targetAt(node) {
            if (!this.survey) { return null; }
            var maps = this._byId();
            for (var el = node; el && el !== this.container; el = el.parentElement) {
                if (el.id && maps.questions[el.id]) {
                    return { target: { kind: 'question', name: maps.questions[el.id].name }, element: el };
                }
                if (el.id && maps.panels[el.id]) {
                    return { target: { kind: 'panel', name: maps.panels[el.id].name }, element: el };
                }
                if (el.classList && el.classList.contains('sd-item')) {
                    var input = el.querySelector('input');
                    var owner = el.parentElement;
                    while (owner && owner !== this.container && !(owner.id && maps.questions[owner.id])) { owner = owner.parentElement; }
                    if (input && owner && owner.id && maps.questions[owner.id]) {
                        return { target: { kind: 'choice', name: maps.questions[owner.id].name, value: input.value }, element: el };
                    }
                }
            }
            var survey = this.survey;
            var page = survey.state === 'starting' ? survey.startPage : survey.currentPage;
            if (!page) { return null; }
            var pageEl = this.container.querySelector('.sd-body') || this.container;
            return { target: { kind: 'page', index: survey.pages.indexOf(page), name: page.name }, element: pageEl };
        }

        /**
         * Shows an element chosen in the editor: goes to its page, scrolls to it, outlines it briefly. SurveyJS draws a
         * new page asynchronously, so the element is looked for over a few frames.
         * @param {function({ok: boolean, reason?: string})} done reason: missing (not in this survey) | hidden (condition
         *   not met, or a question of an answer that is not chosen)
         */
        show(target, done) {
            var survey = this.survey;
            if (!survey) { done({ ok: false, reason: 'missing' }); return; }
            var item = null;
            var page = null;
            if (target.kind === 'page') {
                page = survey.pages[target.index] || null;
            } else if (target.kind === 'panel') {
                item = survey.getPanelByName(target.name);
            } else {
                var name = target.name;
                item = this.questionsOf(survey).filter(function (q) { return q.name === name; })[0] || null;
            }
            if (!page && item) {
                for (var up = item; up && !page; up = up.parentQuestion || up.parent) {
                    if (up.isPage) { page = up; }
                }
                page = page || item.page || null;
            }
            if (!page || (!item && target.kind !== 'page')) { done({ ok: false, reason: 'missing' }); return; }
            if (!page.isVisible || (item && !item.isVisible)) { done({ ok: false, reason: 'hidden' }); return; }

            var current = survey.state === 'starting' ? survey.startPage : survey.currentPage;
            if (page !== current) {
                this._showPage(page);
                this._refreshPages();
            }
            var self = this;
            var find = function () {
                if (target.kind === 'page') { return self.container.querySelector('.sd-body') || self.container; }
                var element = document.getElementById(item.id);
                if (element && target.kind === 'choice') {
                    var value = String(target.value);
                    var inputs = element.querySelectorAll('.sd-item input');
                    for (var i = 0; i < inputs.length; i++) {
                        if (inputs[i].value === value) { return inputs[i].closest('.sd-item'); }
                    }
                }
                return element;
            };
            var tries = 0;
            var attempt = function () {
                if (self.survey !== survey) { done({ ok: false, reason: 'missing' }); return; } // rebuilt meanwhile
                var element = find();
                if (!element && tries < 20) { tries += 1; requestAnimationFrame(attempt); return; }
                if (!element) { done({ ok: false, reason: 'hidden' }); return; }
                element.scrollIntoView({ block: 'center' });
                self._flash(element);
                done({ ok: true });
            };
            attempt();
        }

        _flash(element) {
            document.querySelectorAll('.lp-flash').forEach(function (el) { el.classList.remove('lp-flash', 'lp-fade'); });
            if (!element) { return; }
            element.classList.add('lp-flash');
            setTimeout(function () { element.classList.add('lp-fade'); }, 1200);
            setTimeout(function () { element.classList.remove('lp-flash', 'lp-fade'); }, 2800);
        }

        _hover(node) {
            var hit = node && this.container.contains(node) ? this.targetAt(node) : null;
            var element = hit ? hit.element : null;
            if (element === this.hovered) { return; }
            if (this.hovered) { this.hovered.classList.remove('lp-hover'); }
            this.hovered = element;
            if (element) { element.classList.add('lp-hover'); }
        }
    }

    var preview = new SurveyPreview(document);
    preview._setupPageNavigation();
    if (cfg.live) {
        preview.startLive();
    } else {
        preview.build(cfg.survey, false);
        if (cfg.themeLive) { preview.startThemeLive(); }
    }
    window.__preview = preview;
})();
