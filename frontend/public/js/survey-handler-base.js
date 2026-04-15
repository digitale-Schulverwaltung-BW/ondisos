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
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
