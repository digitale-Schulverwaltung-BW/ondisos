/**
 * SurveyJS Handler for WordPress
 *
 * WordPress-adapted version of survey-handler.js
 *
 * Key differences from standalone version:
 * - Uses WP nonces instead of session CSRF tokens
 * - Reads config from data attributes instead of global object
 * - Submits to admin-ajax.php instead of save.php
 * - Supports multiple forms on same page
 * - Nonce regeneration from response
 */

class SurveyHandlerWP extends SurveyHandlerBase {
    constructor(container) {
        super(container);
        this.container = container;
        this.formKey = container.dataset.formKey;
        this.version = container.dataset.version || '1.0.0';
        this.surveyJson = null;
        this.themeJson = null;
        this.prefillData = '';
        this.nonce = container.dataset.nonce;
        this.ajaxUrl = container.dataset.ajaxUrl;
        this.survey = null;
    }

    /**
     * Initialize survey
     */
    async init() {
        try {
            // Load JSON from script tags (UTF-8 safe)
            const surveyScriptId = this.container.dataset.surveyJsonId;
            const themeScriptId = this.container.dataset.themeJsonId;

            const surveyScript = document.getElementById(surveyScriptId);
            const themeScript = document.getElementById(themeScriptId);

            if (!surveyScript) {
                throw new Error('Survey JSON script tag not found: ' + surveyScriptId);
            }
            if (!themeScript) {
                throw new Error('Theme JSON script tag not found: ' + themeScriptId);
            }

            this.surveyJson = JSON.parse(surveyScript.textContent);
            this.themeJson = JSON.parse(themeScript.textContent);
            this.prefillData = this.container.dataset.prefill || '';

            // Get prefill data if present
            const prefillData = this.getPrefillData();

            // Create survey model
            this.survey = new Survey.Model(this.surveyJson);

            // Apply prefill data
            if (prefillData) {
                this.survey.data = prefillData;
                this.survey.mode = 'edit'; // Allow editing
            }

            // Apply theme
            if (this.themeJson && Object.keys(this.themeJson).length > 0) {
                this.survey.applyTheme(this.themeJson);
            }

            // Setup completion handler
            this.survey.onComplete.add((sender) => this.handleComplete(sender));

            // Clear loading message
            this.container.innerHTML = '';

            // Render survey
            this.survey.render(this.container);

        } catch (error) {
            console.error('Survey initialization failed:', error);
            console.error('Error details:', error.message);
            console.error('Stack:', error.stack);
            this.showError('Formular konnte nicht geladen werden: ' + error.message);
        }
    }

    /**
     * Get prefill data from data attribute or URL parameter
     */
    getPrefillData() {
        // Try data attribute first (from shortcode)
        if (this.prefillData) {
            try {
                const decoded = atob(this.prefillData);
                return JSON.parse(decoded);
            } catch (e) {
                console.error('Invalid prefill data from shortcode:', e);
            }
        }

        // Try URL parameter
        const urlParams = new URLSearchParams(window.location.search);
        const prefillParam = urlParams.get('prefill');
        if (prefillParam) {
            try {
                const decoded = atob(prefillParam);
                return JSON.parse(decoded);
            } catch (e) {
                console.error('Invalid prefill data from URL:', e);
            }
        }

        return null;
    }

    /**
     * Handle survey completion
     */
    async handleComplete(sender) {
        const data = sender.data;

        // SurveyJS only includes answered questions in data.
        // Ensure ALL questions are present so the email table and DB have every field.
        sender.getAllQuestions(false).forEach(question => {
            const type = question.getType();

            // html questions are presentational (no value); file questions are uploaded
            // separately via addFileUploads() — exclude both from survey_data
            if (type === 'html' || type === 'file') {
                delete data[question.name]; // remove if SurveyJS placed it there
                return;
            }

            if (question.name in data) return;

            // Re-inject autofill sentinels from hidden fields so the backend can enrich them
            if (question.defaultValue === '_autofill') {
                data[question.name] = '_autofill';
            } else {
                // Include unanswered visible fields as empty string
                data[question.name] = '';
            }
        });

        // Remove consent fields
        Object.keys(data)
            .filter(k => k.startsWith('consent_'))
            .forEach(k => delete data[k]);

        // Extract field types from survey definition
        data._fieldTypes = this.extractFieldTypes(sender);

        // Prepare form data
        const formData = new FormData();
        formData.append('action', 'ondisos_submit');
        formData.append('form_key', this.formKey);
        formData.append('nonce', this.nonce);
        formData.append('meta', JSON.stringify({
            formular: this.formKey,
            version: this.version,
            timestamp: new Date().toISOString()
        }));

        // Add file uploads FIRST (needs base64 content from question.value)
        this.addFileUploads(formData, sender);

        // Strip base64 content from file fields AFTER extracting files,
        // so the stored JSON contains only name/type references, not the full binary
        this.stripFileContent(data);

        formData.append('survey_data', JSON.stringify(this._sortDataByQuestionOrder(data, sender)));

        // Submit to server
        try {
            const result = await this.submitForm(formData);

            if (!result.success) {
                this.showError(result.error || 'Ein Fehler ist aufgetreten');
            } else {
                // Update nonce for next submission
                if (result.new_nonce) {
                    this.nonce = result.new_nonce;
                    this.container.dataset.nonce = result.new_nonce;
                }

                // Show warnings if any
                if (result.warnings && result.warnings.length > 0) {
                    this.showWarnings(result.warnings);
                }

                // Show prefill link if provided
                if (result.prefill_link) {
                    this.showPrefillLink(result.prefill_link);
                }

                // Show download buttons (PDF and/or iCal) if available
                const hasPdf  = result.pdf_download  && result.pdf_download.enabled;
                const hasIcal = result.ical_download && result.ical_download.enabled;

                // Auto-download PDF if marked as required
                if (hasPdf && result.pdf_download.required) {
                    this._triggerAutoDownload(result.pdf_download);
                }

                if (hasPdf || hasIcal) {
                    this.showDownloadButtons(
                        hasPdf  ? result.pdf_download  : null,
                        hasIcal ? result.ical_download : null
                    );
                }
            }
        } catch (error) {
            // Network-level failure (no connection, timeout, etc.)
            console.error('Submission error:', error);
            this.showError('Netzwerkfehler beim Senden der Anmeldung. Bitte versuchen Sie es erneut.');
        }
    }

    /**
     * Submit form to WordPress AJAX endpoint
     */
    async submitForm(formData) {
        const response = await fetch(this.ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        // Always parse JSON — error messages from the server are in the body,
        // not the HTTP status. If parsing fails, return a generic error object.
        return await response.json().catch(() => ({
            success: false,
            error: `Server-Fehler (HTTP ${response.status})`
        }));
    }

    /**
     * Build the WordPress AJAX proxy URL for a PDF download.
     * Extracts the token from the backend-relative URL and routes it through admin-ajax.php.
     */
    _pdfDownloadUrl(pdfInfo) {
        const token = new URL('http://x/' + pdfInfo.url).searchParams.get('token');
        if (!token) return null;
        return this.ajaxUrl + '?action=ondisos_pdf_download&token=' + encodeURIComponent(token);
    }

    /**
     * Programmatically trigger a PDF download using fetch + Blob URL.
     * Uses fetch so it works regardless of user-gesture context (we're behind an await).
     * The download button is still shown as a fallback if this fails.
     */
    async _triggerAutoDownload(pdfInfo) {
        const downloadUrl = this._pdfDownloadUrl(pdfInfo);
        if (!downloadUrl) return;
        try {
            const response = await fetch(downloadUrl, { credentials: 'same-origin' });
            if (!response.ok) return;
            const blob = await response.blob();
            const blobUrl = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = blobUrl;
            a.download = 'anmeldebestaetigung.pdf';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(blobUrl);
        } catch (e) {
            console.warn('Auto-download failed, button still available:', e);
        }
    }

    /**
     * Build the PDF download card element.
     * Routes the download through the WordPress AJAX proxy so the browser
     * never needs to reach the backend directly.
     */
    _buildPdfCard(pdfInfo) {
        const downloadUrl = this._pdfDownloadUrl(pdfInfo);
        if (!downloadUrl) return document.createTextNode('');

        const title = pdfInfo.title || 'Bestätigung herunterladen';

        const card = document.createElement('div');
        card.style.cssText = 'flex: 1; min-width: 220px; padding: 20px; background: #f0f8ff; border-radius: 6px; border-left: 4px solid #4CAF50; box-shadow: 0 2px 4px rgba(0,0,0,0.1);';
        card.innerHTML = `
            <div style="display: flex; align-items: center; margin-bottom: 12px;">
                <span style="font-size: 32px; margin-right: 12px;">📄</span>
                <strong style="font-size: 18px; color: #2c3e50;">${this.escapeHtml(title)}</strong>
            </div>
            <p style="margin-bottom: 15px; line-height: 1.6; color: #555;">
                ${pdfInfo.required
                    ? 'Falls der automatische Download nicht funktioniert hat, können Sie Ihre Anmeldebestätigung hier erneut herunterladen.'
                    : 'Laden Sie Ihre Anmeldebestätigung als PDF herunter.'}
            </p>
            <a href="${this.escapeHtml(downloadUrl)}"
                download
                style="display: inline-block; padding: 12px 24px; background: #4CAF50; color: white; text-decoration: none; border-radius: 4px; font-weight: bold;">
                📥 ${this.escapeHtml(title)}
            </a>
        `;

        const link = card.querySelector('a');
        link.addEventListener('mouseenter', () => { link.style.background = '#45a049'; });
        link.addEventListener('mouseleave', () => { link.style.background = '#4CAF50'; });

        return card;
    }

    /**
     * Show prefill link in completed page
     */
    showPrefillLink(link) {
        const completed = this.container.querySelector('.sd-completedpage');

        if (completed) {
            const linkDiv = document.createElement('div');
            linkDiv.style.cssText = 'margin-top: 20px; padding: 15px; background: #e3f2fd; border-radius: 4px;';
            linkDiv.innerHTML = `
                <strong>🔗 Weitere Anmeldungen?</strong>
                <p style="margin-bottom: 10px;">Nutzen Sie diesen Link, um weitere Azubis mit den gleichen Firmendaten anzumelden
                (Sie können diesen Link auch in Ihren Bookmarks abspeichern!):</p>
                <input type="text" value="${this.escapeHtml(link)}"
                    readonly onclick="this.select()"
                    style="width: 100%; max-width: 100%; padding: 8px; margin-bottom: 10px; font-family: monospace; box-sizing: border-box; overflow: hidden; text-overflow: ellipsis; display: block;">
                <button onclick="navigator.clipboard.writeText('${this.escapeHtml(link)}'); alert('Link kopiert!')">
                    📋 In Zwischenablage kopieren
                </button>
            `;
            completed.appendChild(linkDiv);
        }
    }

    /**
     * Show error message as a notification at the top of the container.
     * Uses prepend so it is always visible regardless of survey state.
     */
    showError(message) {
        // Remove any previous error notification to avoid duplicates
        this.container.querySelector('.ondisos-error-notification')?.remove();

        const errorDiv = document.createElement('div');
        errorDiv.className = 'ondisos-error-notification';
        errorDiv.style.cssText = [
            'color: #721c24',
            'background: #f8d7da',
            'border: 1px solid #f5c6cb',
            'border-radius: 4px',
            'padding: 16px 20px',
            'margin-bottom: 16px',
        ].join(';');
        errorDiv.innerHTML = `
            <strong>Fehler beim Verarbeiten der Daten</strong>
            <p style="margin:8px 0 0">${this.escapeHtml(message)}</p>
            <p style="margin:4px 0 0"><small>Bitte versuchen Sie es erneut oder kontaktieren Sie uns direkt.</small></p>
        `;

        this.container.prepend(errorDiv);
        errorDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

}

/**
 * Initialize all survey containers when DOM is ready
 */
document.addEventListener('DOMContentLoaded', () => {
    const containers = document.querySelectorAll('.ondisos-survey-container');

    containers.forEach(container => {
        const handler = new SurveyHandlerWP(container);
        handler.init().catch(error => {
            console.error('Survey initialization failed for container:', container.id, error);
        });
    });
});
