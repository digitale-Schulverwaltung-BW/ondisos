<?php
declare(strict_types=1);

/**
 * Frontend Standard Messages
 *
 * These are the default messages for the public-facing frontend.
 * DO NOT modify this file directly for local customizations.
 *
 * For site-specific overrides, create a messages.local.php file
 * (see messages.example.php for template).
 */

return [
    /**
     * Error Messages
     */
    'errors' => [
        'csrf_load_failed' => 'Sicherheitstoken konnte nicht geladen werden',
        'csrf_invalid' => 'Ungültiges Sicherheitstoken. Bitte laden Sie die Seite neu.',
        'form_load_failed' => 'Formular konnte nicht geladen werden. Bitte laden Sie die Seite neu.',
        'submission_failed' => 'Fehler beim Senden der Anmeldung. Bitte versuchen Sie es erneut. {{contact}}',
        'generic_error' => 'Ein Fehler ist aufgetreten. {{contact}}',
        'data_processing_failed' => 'Fehler beim Verarbeiten der Daten',
        'try_again_or_contact' => 'Bitte versuchen Sie es erneut oder kontaktieren Sie uns direkt.',
        'unknown_form' => 'Unbekanntes Formular',
        'form_not_configured' => 'Formular ist nicht konfiguriert',
        'invalid_data' => 'Ungültige Daten übermittelt',
        'required_field_missing' => 'Erforderliches Feld fehlt: {{field}}',

        // File Upload Errors
        'file_too_large' => 'Datei zu groß (max {{maxSize}}MB)',
        'file_type_not_allowed' => 'Dateityp nicht erlaubt: {{extension}}',
        'upload_failed' => 'Upload fehlgeschlagen. {{contact}}',

        // Backend API Errors
        'backend_unreachable' => 'Backend-Server nicht erreichbar. {{contact}}',
        'backend_unavailable' => 'Backend-Server nicht erreichbar. {{contact}}',
        'backend_error' => 'Fehler beim Speichern der Daten. {{contact}}',
        'timeout' => 'Zeitüberschreitung. Bitte versuchen Sie es erneut.',

        // PDF Download Errors
        'pdf' => [
            'missing_token' => 'Fehlender Download-Token',
            'invalid_token_format' => 'Ungültiges Token-Format',
            'invalid_token' => 'Ungültiger oder abgelaufener Download-Link',
            'invalid_request' => 'Ungültige Anfrage',
            'not_found' => 'Anmeldung nicht gefunden',
            'download_failed' => 'PDF-Download fehlgeschlagen',
            'download_failed_title' => 'PDF-Download nicht möglich',
            'download_failed_hint' => 'Der Download-Link ist möglicherweise abgelaufen oder ungültig. Links sind in der Regel 30 Minuten gültig.',
        ],
        'unexpected_error' => 'Ein unerwarteter Fehler ist aufgetreten. {{contact}}',
    ],

    /**
     * Success Messages
     */
    'success' => [
        'submission_complete' => 'Vielen Dank! Ihre Anmeldung wurde erfolgreich übermittelt.',
        'link_copied' => 'Link kopiert!',
        'file_uploaded' => 'Datei erfolgreich hochgeladen',
    ],

    /**
     * UI Labels and Text
     */
    'ui' => [
        // PDF Download Box
        'pdf_download_title' => 'Bestätigung herunterladen',
        'pdf_download_description' => 'Laden Sie Ihre Anmeldebestätigung als PDF herunter.',
        'pdf_download_expires' => 'Link gültig für {{minutes}} Minuten',

        // Prefill Link Box
        'prefill_link_title' => '🔗 Weitere Anmeldungen?',
        'prefill_link_description' => 'Nutzen Sie diesen Link, um weitere Azubis mit den gleichen Firmendaten anzumelden',
        'prefill_link_bookmark' => '(Sie können diesen Link auch in Ihren Bookmarks abspeichern!)',
        'copy_to_clipboard' => '📋 In Zwischenablage kopieren',

        // General UI
        'warning' => '⚠️ Hinweis:',
        'info' => 'ℹ️ Information:',
        'loading' => 'Lädt...',
        'please_wait' => 'Bitte warten...',
        'submitting' => 'Wird gesendet...',

        // Form Labels
        'required' => 'Pflichtfeld',
        'optional' => 'Optional',
        'choose_file' => 'Datei wählen',
        'no_file_chosen' => 'Keine Datei gewählt',
        'remove_file' => 'Datei entfernen',

        // Buttons
        'submit' => 'Absenden',
        'cancel' => 'Abbrechen',
        'back' => 'Zurück',
        'next' => 'Weiter',
        'finish' => 'Abschließen',
    ],

    /**
     * HTML Templates
     * These contain HTML markup with placeholders
     */
    'templates' => [
        // Prefill Link Box
        'prefill_link_box' => '<div style="margin-top: 20px; padding: 15px; background: #e3f2fd; border-radius: 4px; border-left: 4px solid #2196f3;">
            <strong style="display: block; margin-bottom: 8px; color: #1976d2;">{{title}}</strong>
            <p style="margin-bottom: 10px; line-height: 1.5;">{{description}}<br><em style="color: #666;">{{bookmark_hint}}</em></p>
            <input type="text" value="{{link}}" readonly onclick="this.select()"
                style="width: 100%; padding: 8px; margin-bottom: 10px; font-family: monospace; font-size: 12px; border: 1px solid #ccc; border-radius: 3px;">
            <button type="button" onclick="navigator.clipboard.writeText(\'{{link}}\').then(() => alert(\'{{copy_success}}\'))"
                style="padding: 8px 16px; background: #2196f3; color: white; border: none; border-radius: 3px; cursor: pointer;">
                {{copy_button}}
            </button>
        </div>',

        // Error Box
        'error_box' => '<div style="padding: 15px; background: #ffebee; border-radius: 4px; border-left: 4px solid #f44336; margin: 15px 0;">
            <strong style="display: block; margin-bottom: 8px; color: #c62828;">{{title}}</strong>
            <p style="margin: 0; color: #666;">{{message}}</p>
        </div>',

        // Success Box
        'success_box' => '<div style="padding: 15px; background: #e8f5e9; border-radius: 4px; border-left: 4px solid #4caf50; margin: 15px 0;">
            <strong style="display: block; margin-bottom: 8px; color: #2e7d32;">{{title}}</strong>
            <p style="margin: 0; color: #666;">{{message}}</p>
        </div>',
    ],

    /**
     * Email Templates
     */
    'email' => [
        // Subject Lines
        'subject_new_anmeldung' => 'Neue Anmeldung: {{formKey}}',
        'subject_with_name' => ' - {{name}}',

        // Email Body
        'body_intro' => 'Es wurde eine neue Anmeldung übermittelt:',
        'body_footer' => "\n\n---\nDiese E-Mail wurde automatisch vom Schulanmeldungs-System generiert.",

        // Field Labels
        'field_formular' => 'Formular',
        'field_name' => 'Name',
        'field_email' => 'E-Mail',
        'field_submitted_at' => 'Eingereicht am',
        'field_data' => 'Daten',
    ],

    /**
     * Validation Messages
     */
    'validation' => [
        'required' => 'Dieses Feld ist erforderlich',
        'email_invalid' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein',
        'min_length' => 'Mindestens {{min}} Zeichen erforderlich',
        'max_length' => 'Maximal {{max}} Zeichen erlaubt',
        'pattern_mismatch' => 'Ungültiges Format',
        'file_required' => 'Bitte wählen Sie eine Datei',
        'consent_required' => 'Bitte akzeptieren Sie die Bedingungen',
    ],

    /**
     * Contact Information
     * Override these in messages.local.php for site-specific contact info
     */
    'contact' => [
        'support_email' => '', // Leave empty, override in messages.local.php
        'support_text' => '',  // e.g., "Bei Problemen: sekretariat@example.com"
    ],

    /**
     * Form Configuration Messages
     */
    'forms' => [
        'bs' => [
            'title' => 'Anmeldung Berufsfachschule',
            'description' => 'Bitte füllen Sie das folgende Formular aus.',
        ],
        'bk' => [
            'title' => 'Anmeldung Berufskolleg',
            'description' => 'Bitte füllen Sie das folgende Formular aus.',
        ],
    ],

    /**
     * System Availability / Maintenance Messages
     * Shown when the backend is unreachable at form load time.
     */
    'maintenance' => [
        'unavailable_title'       => 'System nicht verfügbar',
        'unavailable_heading'     => 'System vorübergehend nicht erreichbar',
        'unavailable_description' => 'Der Anmeldeserver antwortet derzeit nicht.',
        'unavailable_hint'        => 'Bitte versuchen Sie es in wenigen Minuten erneut.',
    ],

    /**
     * API Error Messages
     */
    'api' => [
        'errors' => [
            'invalid_method' => 'Invalid request method',
            'no_data' => 'Keine Formulardaten empfangen',
        ],
    ],
];
