/**
 * SurveyJS Handler
 * Manages form initialization, submission, and file handling
 */

class SurveyHandler {
    constructor(config) {
        this.config = config;
        this.survey = null;
        this.csrfToken = '';
    }

    /**
     * Initialize survey
     */
    async init() {
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
            throw new Error('Sicherheitstoken konnte nicht geladen werden');
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

        throw new Error('No survey configuration provided');
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
                this.showError(result.error || 'Ein Fehler ist aufgetreten');
            } else {
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
     * Show prefill link in completed page
     * 
     */
    showPrefillLink(link) {
        const completed = document.querySelector('.sd-completedpage');
        
        if (completed) {
            const linkDiv = document.createElement('div');
            linkDiv.style.cssText = 'margin-top: 20px; padding: 15px; background: #e3f2fd; border-radius: 4px;';
            linkDiv.innerHTML = `
                <strong>🔗 Weitere Anmeldungen?</strong>
                <p>Nutzen Sie diesen Link, um weitere Azubis mit den gleichen Firmendaten anzumelden:</p>
                <input type="text" value="${this.escapeHtml(link)}" 
                    readonly onclick="this.select()" 
                    style="width: 100%; padding: 8px; font-family: monospace;">
                <button onclick="navigator.clipboard.writeText('${this.escapeHtml(link)}'); alert('Link kopiert!')">
                    📋 In Zwischenablage kopieren
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
                    <h3>Fehler beim Verarbeiten der Daten</h3>
                    <p>${this.escapeHtml(message)}</p>
                    <p><small>Bitte versuchen Sie es erneut oder kontaktieren Sie uns direkt.</small></p>
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

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    if (window.surveyConfig) {
        const handler = new SurveyHandler(window.surveyConfig);
        handler.init().catch(error => {
            console.error('Survey initialization failed:', error);
            alert('Formular konnte nicht geladen werden. Bitte laden Sie die Seite neu.');
        });
    }
});