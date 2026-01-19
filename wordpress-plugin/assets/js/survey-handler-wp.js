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

class SurveyHandlerWP {
    constructor(container) {
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

        // Remove consent fields
        Object.keys(data)
            .filter(k => k.startsWith('consent_'))
            .forEach(k => delete data[k]);

        // Extract field types from survey definition
        data._fieldTypes = this.extractFieldTypes(sender);

        // Prepare form data
        const formData = new FormData();
        formData.append('action', 'anmeldung_submit');
        formData.append('form_key', this.formKey);
        formData.append('nonce', this.nonce);
        formData.append('survey_data', JSON.stringify(data));
        formData.append('meta', JSON.stringify({
            formular: this.formKey,
            version: this.version,
            timestamp: new Date().toISOString()
        }));

        // Add file uploads
        this.addFileUploads(formData, sender);

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
            }
        } catch (error) {
            console.error('Submission error:', error);
            this.showError('Fehler beim Senden der Anmeldung. Bitte versuchen Sie es erneut.');
        }
    }

    /**
     * Extract field types from survey model
     */
    extractFieldTypes(sender) {
        const fieldTypes = {};

        sender.getAllQuestions().forEach(question => {
            const name = question.name;
            const type = question.getType();

            // Build type info object
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
     * Add file uploads to form data
     */
    addFileUploads(formData, sender) {
        // Find all file questions
        const fileQuestions = sender.getAllQuestions()
            .filter(q => q.getType() === 'file');

        fileQuestions.forEach(question => {
            const files = question.value;

            if (files && files.length > 0) {
                files.forEach((file, index) => {
                    const fieldName = `${question.name}_${index}`;
                    formData.append(fieldName, file);
                });
            }
        });
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

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        return await response.json();
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
     * Show error message
     */
    showError(message) {
        const completed = this.container.querySelector('.sd-completedpage');

        if (completed) {
            completed.innerHTML = `
                <div style="color: #dc3545; padding: 20px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;">
                    <h3>Fehler beim Verarbeiten der Daten</h3>
                    <p>${this.escapeHtml(message)}</p>
                    <p><small>Bitte versuchen Sie es erneut oder kontaktieren Sie uns direkt.</small></p>
                </div>
            `;
        } else {
            // If survey not yet rendered, show in container
            this.container.innerHTML = `
                <div class="anmeldung-error">
                    <p>${this.escapeHtml(message)}</p>
                </div>
            `;
        }
    }

    /**
     * Show warnings (non-fatal)
     */
    showWarnings(warnings) {
        const completed = this.container.querySelector('.sd-completedpage');

        if (completed && warnings.length > 0) {
            const warningHtml = warnings
                .map(w => `<li>${this.escapeHtml(w)}</li>`)
                .join('');

            const warningDiv = document.createElement('div');
            warningDiv.style.cssText = 'color: #856404; padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; margin-top: 10px;';
            warningDiv.innerHTML = `
                <strong>⚠️ Hinweis:</strong>
                <ul style="margin: 5px 0 0 20px;">${warningHtml}</ul>
            `;

            completed.appendChild(warningDiv);
        }
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

/**
 * Initialize all survey containers when DOM is ready
 */
document.addEventListener('DOMContentLoaded', () => {
    const containers = document.querySelectorAll('.anmeldung-survey-container');

    containers.forEach(container => {
        const handler = new SurveyHandlerWP(container);
        handler.init().catch(error => {
            console.error('Survey initialization failed for container:', container.id, error);
        });
    });
});
