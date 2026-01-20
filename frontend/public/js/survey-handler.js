/**
 * SurveyJS Handler
 * Manages form initialization, submission, and file handling
 */

class SurveyHandler {
    constructor(config) {
        this.config = config;
        this.survey = null;
        this.csrfToken = '';
        this.messages = null; // Will be loaded from API
    }

    /**
     * Initialize survey
     */
    async init() {
        // Load messages from API
        await this.loadMessages();

        // Fetch CSRF token
        await this.fetchCsrfToken();

        // Check for prefill parameter
        const urlParams = new URLSearchParams(window.location.search);
        const prefillData = this.getPrefillData(urlParams);

        // Load survey JSON
        const surveyJson = await this.loadSurveyJson();
        
        // Create survey model
        this.survey = new Survey.Model(surveyJson);

        // Apply prefill data
        if (prefillData) {
            this.survey.data = prefillData;
            this.survey.mode = 'edit'; // Allow editing
        }
        // Apply theme
        if (this.config.themeJson || this.config.themeUrl) {
            const theme = await this.loadTheme();
            if (theme) {
                this.survey.applyTheme(theme);
            }
        }

        // Setup completion handler
        this.survey.onComplete.add((sender) => this.handleComplete(sender));

        // Render survey
        this.survey.render(document.getElementById(this.config.containerId));
    }

    /**
     * Load messages from API
     */
    async loadMessages() {
        try {
            const response = await fetch('api/messages.json.php');
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            this.messages = await response.json();
        } catch (error) {
            console.error('Failed to load messages, using defaults:', error);
            // Fallback to default messages
            this.messages = this.getDefaultMessages();
        }
    }

    /**
     * Get default fallback messages (used if API fails)
     */
    getDefaultMessages() {
        return {
            errors: {
                csrf_load_failed: 'Sicherheitstoken konnte nicht geladen werden',
                form_load_failed: 'Formular konnte nicht geladen werden. Bitte laden Sie die Seite neu.',
                submission_failed: 'Fehler beim Senden der Anmeldung. Bitte versuchen Sie es erneut.',
                generic_error: 'Ein Fehler ist aufgetreten',
                data_processing_failed: 'Fehler beim Verarbeiten der Daten',
                try_again_or_contact: 'Bitte versuchen Sie es erneut oder kontaktieren Sie uns direkt.',
            },
            success: {
                link_copied: 'Link kopiert!',
            },
            ui: {
                prefill_link_title: '🔗 Weitere Anmeldungen?',
                prefill_link_description: 'Nutzen Sie diesen Link, um weitere Azubis mit den gleichen Firmendaten anzumelden',
                prefill_link_bookmark: '(Sie können diesen Link auch in Ihren Bookmarks abspeichern!)',
                copy_to_clipboard: '📋 In Zwischenablage kopieren',
                warning: '⚠️ Hinweis:',
            },
        };
    }

    /**
     * Get message by dot notation key
     */
    msg(key, defaultValue = '') {
        const parts = key.split('.');
        let value = this.messages;

        for (const part of parts) {
            if (!value || !value[part]) {
                return defaultValue || `[missing: ${key}]`;
            }
            value = value[part];
        }

        return value;
    }

    /**
     * Format message with placeholder replacement
     */
    formatMsg(key, replacements = {}, defaultValue = '') {
        let message = this.msg(key, defaultValue);

        Object.keys(replacements).forEach(k => {
            message = message.replace(new RegExp(`{{${k}}}`, 'g'), replacements[k]);
        });

        return message;
    }

    /**
     * Retrieve and decode prefill data from URL parameter
     * Returns null if no valid data is found
     */
    getPrefillData(urlParams) {
        const prefillParam = urlParams.get('prefill');
        if (!prefillParam) return null;
        
        try {
            const decoded = atob(prefillParam);
            return JSON.parse(decoded);
        } catch (e) {
            console.error('Invalid prefill data:', e);
            return null;
        }
    }

    /**
     * Fetch CSRF token from server
     */
    async fetchCsrfToken() {
        try {
            const response = await fetch('csrf_token.php');
            const data = await response.json();
            this.csrfToken = data.token;
        } catch (error) {
            console.error('Failed to fetch CSRF token:', error);
            throw new Error(this.msg('errors.csrf_load_failed'));
        }
    }

    /**
     * Load survey JSON
     */
    async loadSurveyJson() {
        if (this.config.surveyJson) {
            return this.config.surveyJson;
        }

        if (this.config.surveyUrl) {
            const response = await fetch(this.config.surveyUrl);
            return await response.json();
        }

        throw new Error(this.msg('errors.form_config_missing', 'No survey configuration provided'));
    }

    /**
     * Load theme
     */
    async loadTheme() {
        if (this.config.themeJson) {
            return this.config.themeJson;
        }

        if (this.config.themeUrl) {
            const response = await fetch(this.config.themeUrl);
            return await response.json();
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
        formData.append('survey_data', JSON.stringify(data));
        formData.append('csrf_token', this.csrfToken);
        formData.append('meta', JSON.stringify({
            formular: this.config.formKey,
            version: this.config.version || '1.0.0',
            timestamp: new Date().toISOString()
        }));

        // Add file uploads
        this.addFileUploads(formData, sender);

        // Submit to server
        try {
            const result = await this.submitForm(formData);

            if (!result.success) {
                this.showError(result.error || this.msg('errors.generic_error'));
            } else {
                // Show warnings if any
                if (result.warnings && result.warnings.length > 0) {
                    this.showWarnings(result.warnings);
                }

                // Show PDF download if available
                if (result.pdf_download && result.pdf_download.enabled) {
                    this.showPdfDownload(result.pdf_download);
                }

                // Show prefill link if provided
                if (result.prefill_link) {
                    this.showPrefillLink(result.prefill_link);
                }
            }
        } catch (error) {
            console.error('Submission error:', error);
            this.showError(this.msg('errors.submission_failed'));
        }
    }

    /**
     * Show PDF download button in completed page
     */
    showPdfDownload(pdfInfo) {
        const completed = document.querySelector('.sd-completedpage');

        if (completed) {
            const pdfDiv = document.createElement('div');
            pdfDiv.className = 'pdf-download-box';
            pdfDiv.style.cssText = 'margin-top: 20px; padding: 20px; background: #f0f8ff; border-radius: 6px; border-left: 4px solid #4CAF50; box-shadow: 0 2px 4px rgba(0,0,0,0.1);';

            const expiresMinutes = Math.floor(pdfInfo.expires_in / 60);
            const title = pdfInfo.title || this.msg('ui.pdf_download_title', 'Bestätigung herunterladen');
            const description = this.msg('ui.pdf_download_description', 'Laden Sie Ihre Anmeldebestätigung als PDF herunter.');
            const expiresText = this.formatMsg('ui.pdf_download_expires',
                { minutes: expiresMinutes },
                `Link gültig für ${expiresMinutes} Minuten`
            );

            pdfDiv.innerHTML = `
                <div style="display: flex; align-items: center; margin-bottom: 12px;">
                    <span style="font-size: 32px; margin-right: 12px;">📄</span>
                    <strong style="font-size: 18px; color: #2c3e50;">${this.escapeHtml(title)}</strong>
                </div>
                <p style="margin-bottom: 15px; line-height: 1.6; color: #555;">
                    ${this.escapeHtml(description)}
                </p>
                <a href="${this.escapeHtml(pdfInfo.url)}"
                    target="_blank"
                    style="display: inline-block; padding: 12px 24px; background: #4CAF50; color: white; text-decoration: none; border-radius: 4px; font-weight: bold; transition: background 0.3s;">
                    📥 ${this.escapeHtml(title)}
                </a>
                <p style="margin-top: 12px; font-size: 12px; color: #999;">
                    <em>💡 ${this.escapeHtml(expiresText)}</em>
                </p>
            `;

            // Add hover effect
            const downloadLink = pdfDiv.querySelector('a');
            downloadLink.addEventListener('mouseenter', () => {
                downloadLink.style.background = '#45a049';
            });
            downloadLink.addEventListener('mouseleave', () => {
                downloadLink.style.background = '#4CAF50';
            });

            // Insert at the top of completed page (before other elements)
            completed.insertBefore(pdfDiv, completed.firstChild);
        }
    }

    /**
     * Show prefill link in completed page
     */
    showPrefillLink(link) {
        const completed = document.querySelector('.sd-completedpage');

        if (completed) {
            const linkDiv = document.createElement('div');
            linkDiv.style.cssText = 'margin-top: 20px; padding: 15px; background: #e3f2fd; border-radius: 4px; border-left: 4px solid #2196f3;';
            linkDiv.innerHTML = `
                <strong style="display: block; margin-bottom: 8px; color: #1976d2;">${this.msg('ui.prefill_link_title')}</strong>
                <p style="margin-bottom: 10px; line-height: 1.5;">
                    ${this.msg('ui.prefill_link_description')}<br>
                    <em style="color: #666;">${this.msg('ui.prefill_link_bookmark')}</em>
                </p>
                <input type="text" value="${this.escapeHtml(link)}"
                    readonly onclick="this.select()"
                    style="width: 100%; padding: 8px; margin-bottom: 10px; font-family: monospace; font-size: 12px; border: 1px solid #ccc; border-radius: 3px;">
                <button type="button" onclick="navigator.clipboard.writeText('${this.escapeHtml(link)}').then(() => alert('${this.msg('success.link_copied')}'))"
                    style="padding: 8px 16px; background: #2196f3; color: white; border: none; border-radius: 3px; cursor: pointer;">
                    ${this.msg('ui.copy_to_clipboard')}
                </button>
            `;
            completed.appendChild(linkDiv);
        }
    }
    /**
     * Extract field types from survey model
     * Returns object mapping field names to their types
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
     * Submit form to server
     */
    async submitForm(formData) {
        const url = `save.php?form=${encodeURIComponent(this.config.formKey)}`;

        const response = await fetch(url, {
            method: 'POST',
            body: formData
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        return await response.json();
    }

    /**
     * Show error message
     */
    showError(message) {
        const completed = document.querySelector('.sd-completedpage');

        if (completed) {
            completed.innerHTML = `
                <div style="color: #dc3545; padding: 20px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;">
                    <h3>${this.msg('errors.data_processing_failed')}</h3>
                    <p>${this.escapeHtml(message)}</p>
                    <p><small>${this.msg('errors.try_again_or_contact')}</small></p>
                </div>
            `;
        } else {
            alert(message);
        }
    }

    /**
     * Show warnings (non-fatal)
     */
    showWarnings(warnings) {
        const completed = document.querySelector('.sd-completedpage');

        if (completed && warnings.length > 0) {
            const warningHtml = warnings
                .map(w => `<li>${this.escapeHtml(w)}</li>`)
                .join('');

            const warningDiv = document.createElement('div');
            warningDiv.style.cssText = 'color: #856404; padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; margin-top: 10px;';
            warningDiv.innerHTML = `
                <strong>${this.msg('ui.warning')}</strong>
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

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    if (window.surveyConfig) {
        const handler = new SurveyHandler(window.surveyConfig);
        handler.init().catch(error => {
            console.error('Survey initialization failed:', error);
            // Fallback message if messages haven't loaded yet
            const errorMsg = handler.messages
                ? handler.msg('errors.form_load_failed')
                : 'Formular konnte nicht geladen werden. Bitte laden Sie die Seite neu.';
            alert(errorMsg);
        });
    }
});