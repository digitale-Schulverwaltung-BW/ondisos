/**
 * SurveyJS Handler — Shared Base Class
 *
 * Contains logic shared between SurveyHandler (standalone) and SurveyHandlerWP (WordPress).
 * Must be loaded before either subclass.
 *
 * Subclasses are responsible for:
 * - constructor / init()
 * - handleComplete() — FormData construction differs (CSRF vs nonce)
 * - submitForm()
 * - _buildPdfCard() — proxy routing differs
 * - showPrefillLink() — HTML structure differs
 * - showError() — DOM approach differs
 */

Survey.Serializer.addProperty("question", { name: "placeholderExpression", type: "expression" });

class SurveyHandlerBase {
    /**
     * @param {Document|Element} root - Scope for DOM lookups.
     *   Pass `document` for standalone, or the container element for WordPress.
     */
    constructor(root = document) {
        this._root = root;
    }

    /**
     * Get a localized message. Returns defaultValue if no translation is available.
     * SurveyHandler overrides this with full i18n support.
     *
     * @param {string} key
     * @param {string} defaultValue
     */
    msg(key, defaultValue = '') {
        return defaultValue || key;
    }

    // -------------------------------------------------------------------------
    // Survey data processing
    // -------------------------------------------------------------------------

    /**
     * Extract field types from survey model.
     * Returns object mapping field names to their type info.
     */
    extractFieldTypes(sender) {
        const fieldTypes = {};

        sender.getAllQuestions().forEach(question => {
            const name = question.name;
            const type = question.getType();

            const typeInfo = { type };

            // Include inputType for text fields (date, email, tel, etc.)
            if (type === 'text' && question.inputType) {
                typeInfo.inputType = question.inputType;
            }

            fieldTypes[name] = typeInfo;
        });

        return fieldTypes;
    }

    /**
     * Add file uploads to form data.
     * SurveyJS stores files as {name, type, content: "data:...;base64,..."} objects,
     * not as File objects. We convert each base64 data URL to a Blob for proper upload.
     */
    addFileUploads(formData, sender) {
        sender.getAllQuestions()
            .filter(q => q.getType() === 'file')
            .forEach(question => {
                const files = question.value;
                if (!files || !files.length) return;

                files.forEach((fileObj, index) => {
                    if (!fileObj || !fileObj.content) return;
                    const blob = this.dataUrlToBlob(fileObj.content);
                    formData.append(`${question.name}_${index}`, blob, fileObj.name);
                });
            });
    }

    /**
     * Honeypot: legt ein für Menschen unsichtbares Feld im Formular-Container an.
     * Bots, die alle Felder befüllen, füllen es mit; der Server verwirft solche Anmeldungen.
     * Der Feldname muss zu App\\Utils\\Honeypot::FIELD (PHP) passen.
     *
     * @param {Element} container - Element, in das die Survey gerendert wurde
     */
    addHoneypot(container) {
        if (!container || container.querySelector('input[name="website_url"]')) return;
        const wrap = document.createElement('div');
        wrap.setAttribute('aria-hidden', 'true');
        wrap.style.cssText = 'position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;';
        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'website_url';
        input.tabIndex = -1;
        input.autocomplete = 'off';
        wrap.appendChild(input);
        container.appendChild(wrap);
        this._honeypot = input;
    }

    /** Wert des Honeypot-Feldes (leer bei Menschen). */
    honeypotValue() {
        return this._honeypot ? this._honeypot.value : '';
    }

    /**
     * Convert a base64 data URL to a Blob
     */
    dataUrlToBlob(dataUrl) {
        const parts = dataUrl.split(',');
        const mimeMatch = parts[0].match(/:(.*?);/);
        const mime = mimeMatch ? mimeMatch[1] : 'application/octet-stream';
        const binary = atob(parts[1]);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return new Blob([bytes], { type: mime });
    }

    /**
     * Strip base64 content from file fields in survey data.
     * Replaces each file entry with {name, type} only — the actual file
     * is sent separately via addFileUploads().
     *
     * Detects file fields by value shape (array of objects with a 'content' property)
     * rather than question type, so it works regardless of SurveyJS nesting or version.
     */
    stripFileContent(data) {
        Object.keys(data).forEach(key => {
            const value = data[key];
            if (!Array.isArray(value) || !value.length) return;
            if (!value[0] || typeof value[0] !== 'object' || !('content' in value[0])) return;
            data[key] = value.map(f => ({ name: f.name, type: f.type }));
        });
    }

    // -------------------------------------------------------------------------
    // Shared UI helpers
    // -------------------------------------------------------------------------

    /**
     * Show download buttons (PDF and/or iCal) in the completed page.
     * Buttons are placed side-by-side on wider screens (flex-wrap).
     *
     * _buildPdfCard() is intentionally NOT defined here — it differs between
     * standalone (direct URL) and WordPress (admin-ajax proxy).
     *
     * @param {object|null} pdfInfo  - pdf_download object from server, or null
     * @param {object|null} icalInfo - ical_download object from server, or null
     */
    showDownloadButtons(pdfInfo, icalInfo) {
        const completed = this._root.querySelector('.sd-completedpage');
        if (!completed) return;

        const container = document.createElement('div');
        container.className = 'download-buttons-container';
        container.style.cssText = 'margin-top: 20px; display: flex; flex-wrap: wrap; gap: 16px;';

        if (pdfInfo) {
            container.appendChild(this._buildPdfCard(pdfInfo));
        }
        if (icalInfo) {
            container.appendChild(this._buildIcalCard(icalInfo));
        }

        completed.insertBefore(container, completed.firstChild);
    }

    /**
     * Build the iCal download card element
     */
    _buildIcalCard(icalInfo) {
        const title       = icalInfo.title || this.msg('ui.ical_download_title', 'Termin herunterladen');
        const description = this.msg('ui.ical_download_description', 'Tragen Sie den Termin in Ihren Kalender ein.');

        const card = document.createElement('div');
        card.style.cssText = 'flex: 1; min-width: 220px; padding: 20px; background: #f0f4ff; border-radius: 6px; border-left: 4px solid #1976d2; box-shadow: 0 2px 4px rgba(0,0,0,0.1);';
        card.innerHTML = `
            <div style="display: flex; align-items: center; margin-bottom: 12px;">
                <span style="font-size: 32px; margin-right: 12px;">📅</span>
                <strong style="font-size: 18px; color: #2c3e50;">${this.escapeHtml(title)}</strong>
            </div>
            <p style="margin-bottom: 15px; line-height: 1.6; color: #555;">
                ${this.escapeHtml(description)}
            </p>
            <a href="${this.escapeHtml(icalInfo.url)}"
                style="display: inline-block; padding: 12px 24px; background: #1976d2; color: white; text-decoration: none; border-radius: 4px; font-weight: bold;">
                📥 ${this.escapeHtml(title)}
            </a>
        `;

        const link = card.querySelector('a');
        link.addEventListener('mouseenter', () => { link.style.background = '#1565c0'; });
        link.addEventListener('mouseleave', () => { link.style.background = '#1976d2'; });

        return card;
    }

    /**
     * Show warnings (non-fatal) in the completed page.
     */
    showWarnings(warnings) {
        const completed = this._root.querySelector('.sd-completedpage');

        if (completed && warnings.length > 0) {
            const warningHtml = warnings
                .map(w => `<li>${this.escapeHtml(w)}</li>`)
                .join('');

            const warningDiv = document.createElement('div');
            warningDiv.style.cssText = 'color: #856404; padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; margin-top: 10px;';
            warningDiv.innerHTML = `
                <strong>${this.msg('ui.warning', '⚠️ Hinweis:')}</strong>
                <ul style="margin: 5px 0 0 20px;">${warningHtml}</ul>
            `;

            completed.appendChild(warningDiv);
        }
    }

    /**
     * Extract prefill data from plain (non-base64) query params, restricted to a set of
     * known field names. This lets links be hand-crafted as ?Klasse=5a without encoding,
     * while ignoring unrelated params (tracking params like utm_source or fbclid on WP pages,
     * `form`/`prefill` on the standalone frontend, etc.) that happen to be on the URL.
     *
     * @param {URLSearchParams} urlParams
     * @param {Set<string>} validNames - Question names from the survey definition
     */
    _extractPlainPrefillParams(urlParams, validNames) {
        const plainData = {};
        for (const [key, value] of urlParams.entries()) {
            if (validNames.has(key)) {
                plainData[key] = value;
            }
        }
        return Object.keys(plainData).length > 0 ? plainData : null;
    }

    // -------------------------------------------------------------------------
    // Utility
    // -------------------------------------------------------------------------

    /**
     * Return a copy of data with keys in survey definition order.
     *
     * Without this, prefill fields (set via survey.data = prefillData before render)
     * end up first in the object due to JS insertion-order semantics, causing them
     * to appear at the top of notification emails regardless of form layout.
     * Metadata keys (e.g. _fieldTypes) are appended after the question keys.
     *
     * @param {object} data   - The assembled survey_data object
     * @param {object} sender - The SurveyJS model (provides getAllQuestions)
     */
    _sortDataByQuestionOrder(data, sender) {
        const ordered = {};

        sender.getAllQuestions(false).forEach(question => {
            const name = question.name;
            if (name in data) {
                ordered[name] = data[name];
            }
        });

        // Append any remaining keys not covered by questions (e.g. _fieldTypes)
        Object.keys(data).forEach(key => {
            if (!(key in ordered)) {
                ordered[key] = data[key];
            }
        });

        return ordered;
    }

    /**
     * Wire up placeholderExpression evaluation for all questions in a survey.
     * Called once after Survey.Model is created; handles initial render and value changes.
     */
    _setupDynamicPlaceholders(survey) {
        const update = () => {
            survey.getAllQuestions().forEach(q => {
                if (q["placeholderExpression"]) {
                    const val = survey.runExpression(q["placeholderExpression"]);
                    if (val != null) q.placeholder = String(val);
                }
            });
        };
        survey.onAfterRenderSurvey.add(update);
        survey.onValueChanged.add(update);
    }

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // -------------------------------------------------------------------------
    // Signature on a smartphone (3.2.0a)
    // -------------------------------------------------------------------------

    /**
     * Offer "sign on the smartphone" at every PNG signature field. Call before survey.render().
     * Only the subclasses that can relay call this; the backend preview (same file) never does.
     * Devices whose main pointer is a finger (phones, tablets) sign directly and get no button.
     *
     * @param {object} survey Survey.Model
     * @param {(action: string, data: object) => Promise<object>} request posts to the relay; resolves
     *        to its JSON ({ok: true, …} or {ok: false, error: '<code>'})
     */
    setupSignatureHandoff(survey, request) {
        if (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) {
            return;
        }
        this._sigRequest = request;
        // Standalone: config.formKey; WordPress: formKey from the container
        this._sigFormKey = (this.config && this.config.formKey) || this.formKey || '';
        survey.onAfterRenderQuestion.add((sender, options) => {
            const question = options.question;
            if (question.getType() !== 'signaturepad' || question.isReadOnly) {
                return;
            }
            const format = String(question.dataFormat || 'png').replace('image/', '');
            if (format !== 'png' || options.htmlElement.querySelector('.ondisos-sig-open')) {
                return;
            }
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'ondisos-sig-open';
            button.textContent = '📱 ' + this.msg('signature.button', 'Auf dem Smartphone unterschreiben');
            button.addEventListener('click', () => this._openSignatureDialog(question));
            options.htmlElement.appendChild(button);
        });
    }

    /** Colors the pad actually draws with (theme, field settings or SurveyJS default). */
    _signatureColors(question) {
        const pad = question.signaturePad || {};
        return {
            pen_color: String(pad.penColor || question.penColor || ''),
            background_color: String(pad.backgroundColor || question.backgroundColor || ''),
        };
    }

    _openSignatureDialog(question) {
        this._injectSignatureStyles();
        const t = (key, fallback) => this.msg('signature.' + key, fallback);

        const dialog = document.createElement('dialog');
        dialog.className = 'ondisos-sig';
        dialog.setAttribute('aria-labelledby', 'ondisosSigTitle');
        dialog.innerHTML = `
            <h2 id="ondisosSigTitle" class="ondisos-sig__title"></h2>
            <div class="ondisos-sig__step" data-step="code">
                <p class="ondisos-sig__text" data-text="scan"></p>
                <img class="ondisos-sig__qr" alt="">
                <p class="ondisos-sig__countdown" aria-live="off"></p>
                <p class="ondisos-sig__state" role="status" aria-live="polite"></p>
                <details class="ondisos-sig__link">
                    <summary data-text="link"></summary>
                    <input type="text" readonly>
                    <div class="ondisos-sig__buttons">
                        <a class="ondisos-sig__btn" target="_blank" rel="noopener noreferrer" data-text="open"></a>
                        <button type="button" class="ondisos-sig__btn" data-action="copy"></button>
                    </div>
                </details>
            </div>
            <div class="ondisos-sig__step" data-step="preview" hidden>
                <p class="ondisos-sig__text" data-text="preview"></p>
                <img class="ondisos-sig__preview" alt="">
                <div class="ondisos-sig__buttons">
                    <button type="button" class="ondisos-sig__btn ondisos-sig__btn--primary" data-action="accept"></button>
                    <button type="button" class="ondisos-sig__btn" data-action="discard"></button>
                </div>
            </div>
            <div class="ondisos-sig__step" data-step="message" hidden>
                <p class="ondisos-sig__text ondisos-sig__text--error" data-text="message"></p>
            </div>
            <div class="ondisos-sig__buttons ondisos-sig__footer">
                <button type="button" class="ondisos-sig__btn" data-action="new" hidden></button>
                <button type="button" class="ondisos-sig__btn" data-action="close"></button>
            </div>`;

        const $ = (selector) => dialog.querySelector(selector);
        $('.ondisos-sig__title').textContent = t('dialog_title', 'Auf dem Smartphone unterschreiben');
        $('[data-text="scan"]').textContent = t('dialog_scan', 'Scannen Sie den QR-Code mit Ihrem Smartphone oder Tablet und unterschreiben Sie dort.');
        $('[data-text="link"]').textContent = t('dialog_link', 'Scannen klappt nicht? Link öffnen oder kopieren:');
        $('[data-text="open"]').textContent = t('dialog_open', 'Link öffnen');
        $('[data-action="copy"]').textContent = t('dialog_copy', 'Link kopieren');
        $('[data-text="preview"]').textContent = t('dialog_preview', 'Diese Unterschrift ist angekommen:');
        $('[data-action="accept"]').textContent = t('dialog_accept', 'Übernehmen');
        $('[data-action="discard"]').textContent = t('dialog_discard', 'Verwerfen');
        $('[data-action="new"]').textContent = t('dialog_new_code', 'Neuen Code erzeugen');
        $('[data-action="close"]').textContent = t('dialog_close', 'Schließen');

        const state = { id: '', key: '', deadline: 0, pollTimer: null, tickTimer: null, closed: false, png: '' };

        const show = (step) => {
            dialog.querySelectorAll('.ondisos-sig__step').forEach((el) => { el.hidden = el.dataset.step !== step; });
        };
        const stopTimers = () => {
            clearTimeout(state.pollTimer);
            clearInterval(state.tickTimer);
            state.pollTimer = null;
            state.tickTimer = null;
        };
        const fail = (code) => {
            stopTimers();
            const known = ['expired', 'used', 'invalid', 'rate_limit', 'disabled', 'csrf'];
            const key = code === 'disabled' ? 'dialog_disabled' : 'error_' + (known.includes(code) ? code : 'unavailable');
            $('[data-text="message"]').textContent = code === 'expired'
                ? t('dialog_expired', 'Der QR-Code ist abgelaufen.')
                : t(key, 'Der Dienst ist gerade nicht erreichbar.');
            show('message');
            $('[data-action="new"]').hidden = code === 'disabled';
        };
        const tick = () => {
            const remaining = Math.max(0, Math.round((state.deadline - Date.now()) / 1000));
            const text = Math.floor(remaining / 60) + ':' + String(remaining % 60).padStart(2, '0');
            $('.ondisos-sig__countdown').textContent = t('valid_for', 'Gültig noch {{time}}').replace('{{time}}', text);
            if (remaining === 0) {
                fail('expired');
            }
        };
        const poll = async () => {
            state.pollTimer = null;
            if (state.closed || !state.id) {
                return;
            }
            if (document.hidden) {
                // Background tab: no requests; visibilitychange resumes
                return;
            }
            const result = await this._sigRequest('poll', { id: state.id, key: state.key });
            if (state.closed) {
                return;
            }
            if (!result.ok) {
                if (result.error === 'rate_limit' || result.error === 'unavailable') {
                    state.pollTimer = setTimeout(poll, 6000);
                    return;
                }
                fail(result.error);
                return;
            }
            if (result.status === 'signed' && result.png) {
                stopTimers();
                state.id = '';
                state.png = result.png;
                $('.ondisos-sig__preview').src = result.png;
                show('preview');
                return;
            }
            if (result.status === 'expired') {
                fail('expired');
                return;
            }
            $('.ondisos-sig__state').textContent = result.status === 'scanned'
                ? t('dialog_connected', 'Smartphone verbunden, bitte dort unterschreiben.')
                : '';
            state.pollTimer = setTimeout(poll, 3000);
        };
        const onVisibility = () => {
            if (!document.hidden && state.id && !state.pollTimer && !state.closed) {
                poll();
            }
        };
        const create = async () => {
            stopTimers();
            state.id = '';
            $('[data-action="new"]').hidden = true;
            $('.ondisos-sig__state').textContent = '';
            $('.ondisos-sig__countdown').textContent = '';
            $('.ondisos-sig__qr').removeAttribute('src');
            show('code');

            const title = String(question.processedTitle || question.title || question.name).replace(/<[^>]*>/g, '').trim();
            const result = await this._sigRequest('create', {
                form_key: this._sigFormKey,
                field_name: question.name,
                field_title: title,
                width: parseInt(question.signatureWidth, 10) || 300,
                height: parseInt(question.signatureHeight, 10) || 200,
                ...this._signatureColors(question),
            });
            if (state.closed) {
                return;
            }
            if (!result.ok) {
                fail(result.error);
                return;
            }
            state.id = result.id;
            state.key = result.read_key;
            state.deadline = Date.now() + Math.max(0, Number(result.expires_in) || 0) * 1000;
            // As <img>: the SVG from the backend is never inserted as markup
            $('.ondisos-sig__qr').src = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(result.qr_svg)));
            $('.ondisos-sig__link input').value = result.sign_link;
            $('[data-text="open"]').href = result.sign_link;
            tick();
            state.tickTimer = setInterval(tick, 1000);
            state.pollTimer = setTimeout(poll, 3000);
        };

        dialog.addEventListener('click', async (event) => {
            const action = event.target.closest('[data-action]')?.dataset.action;
            if (action === 'close') {
                dialog.close();
            } else if (action === 'new' || action === 'discard') {
                state.png = '';
                create();
            } else if (action === 'accept') {
                question.value = state.png;
                dialog.close();
            } else if (action === 'copy') {
                await this._copySignatureLink($('.ondisos-sig__link input'), event.target);
            }
        });
        dialog.addEventListener('close', () => {
            state.closed = true;
            stopTimers();
            document.removeEventListener('visibilitychange', onVisibility);
            dialog.remove();
        });
        document.addEventListener('visibilitychange', onVisibility);

        document.body.appendChild(dialog);
        dialog.showModal();
        create();
    }

    /** Clipboard API needs a secure context (https or localhost); plain http on the LAN falls back to execCommand. */
    async _copySignatureLink(input, button) {
        let copied = false;
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(input.value);
                copied = true;
            }
        } catch (e) {
            copied = false;
        }
        if (!copied) {
            input.select();
            try {
                copied = document.execCommand('copy');
            } catch (e) {
                copied = false;
            }
        }
        if (copied) {
            const label = button.textContent;
            button.textContent = this.msg('success.link_copied', 'Link kopiert!');
            setTimeout(() => { button.textContent = label; }, 2000);
        }
    }

    /** Styles of button and dialog, once per page (prefixed: WordPress themes must not collide). */
    _injectSignatureStyles() {
        if (document.getElementById('ondisos-sig-styles')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'ondisos-sig-styles';
        style.textContent = `
            .ondisos-sig-open { margin-top: 8px; padding: 6px 12px; border: 1px solid #ccc; border-radius: 6px;
                background: #fff; cursor: pointer; font-family: inherit; font-size: 14px; color: #333; }
            .ondisos-sig-open:hover { border-color: #888; }
            .ondisos-sig { max-width: min(92vw, 420px); border: 0; border-radius: 10px; padding: 20px 24px;
                box-shadow: 0 10px 40px rgba(0,0,0,.25); font: inherit; text-align: center; }
            .ondisos-sig::backdrop { background: rgba(0,0,0,.45); }
            .ondisos-sig__title { font-size: 1.2em; margin: 0 0 8px; }
            .ondisos-sig__text { margin: 8px 0; line-height: 1.45; }
            .ondisos-sig__text--error { color: #b02a37; }
            .ondisos-sig__qr { width: 220px; height: 220px; display: block; margin: 8px auto; background: #fff; }
            .ondisos-sig__qr:not([src]) { visibility: hidden; }
            .ondisos-sig__countdown { color: #555; font-variant-numeric: tabular-nums; margin: 4px 0; }
            .ondisos-sig__state { color: #146c43; font-weight: 600; min-height: 1.4em; margin: 4px 0; }
            .ondisos-sig__link { margin-top: 8px; text-align: left; font-size: 0.9em; }
            .ondisos-sig__link summary { cursor: pointer; color: #555; }
            .ondisos-sig__link input { width: 100%; box-sizing: border-box; margin: 6px 0; font-size: 0.85em; }
            .ondisos-sig__preview { max-width: 100%; border: 1px dashed #bbb; border-radius: 6px; margin: 8px 0;
                background: #fff; }
            .ondisos-sig__buttons { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }
            .ondisos-sig__footer { margin-top: 16px; }
            .ondisos-sig__btn { padding: 6px 14px; border: 1px solid #ccc; border-radius: 6px; background: #fff;
                cursor: pointer; font: inherit; color: inherit; text-decoration: none; }
            .ondisos-sig__btn--primary { background: #19b394; border-color: #19b394; color: #fff; }
            .ondisos-sig [hidden] { display: none !important; }`;
        document.head.appendChild(style);
    }
}
