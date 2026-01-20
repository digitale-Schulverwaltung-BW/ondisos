<?php
declare(strict_types=1);

/**
 * Backend Standard Messages
 *
 * These are the default messages for the backend admin interface.
 * DO NOT modify this file directly for local customizations.
 *
 * For site-specific overrides, create a messages.local.php file
 * (see messages.example.php for template).
 */

return [
    /**
     * Validation Messages
     */
    'validation' => [
        'required_formular' => 'Formular ist erforderlich',
        'required_name' => 'Name ist erforderlich',
        'required_email' => 'E-Mail ist erforderlich',
        'invalid_email' => 'Ungültige E-Mail-Adresse',
        'name_too_long' => 'Name ist zu lang (max. 255 Zeichen)',
        'email_too_long' => 'E-Mail ist zu lang (max. 255 Zeichen)',
        'invalid_status' => 'Ungültiger Status',
        'invalid_json' => 'Ungültige JSON-Daten',
    ],

    /**
     * Error Messages
     */
    'errors' => [
        'file_too_large' => 'Datei zu groß (max {{maxSize}}MB)',
        'file_type_not_allowed' => 'Dateityp nicht erlaubt: {{extension}}',
        'upload_failed' => 'Upload fehlgeschlagen. {{contact}}',
        'unknown_form' => 'Unbekanntes Formular',
        'invalid_json' => 'Ungültige JSON-Daten',
        'generic_error' => 'Ein unerwarteter Fehler ist aufgetreten. {{contact}}',
        'database_error' => 'Datenbankfehler. {{contact}}',
        'not_found' => 'Eintrag nicht gefunden',
        'already_deleted' => 'Eintrag wurde bereits gelöscht',
        'restore_failed' => 'Wiederherstellen fehlgeschlagen',
        'delete_failed' => 'Löschen fehlgeschlagen',
        'no_entries_selected' => 'Bitte wählen Sie mindestens einen Eintrag aus',
        'expunge_failed' => 'Expunge fehlgeschlagen',
    ],

    /**
     * Success Messages
     */
    'success' => [
        'bulk_action_completed' => '{{count}} Einträge erfolgreich {{action}}',
        'restored' => '✓ Wiederhergestellt! Eintrag #{{id}} wurde wiederhergestellt.',
        'deleted' => '⚠️ Permanent gelöscht! Eintrag #{{id}} wurde permanent aus der Datenbank entfernt.',
        'status_updated' => 'Status erfolgreich aktualisiert',
        'export_completed' => 'Export erfolgreich abgeschlossen',
        'expunge_completed' => 'Expunge erfolgreich durchgeführt. {{count}} Einträge gelöscht.',
    ],

    /**
     * UI Labels and Buttons
     */
    'ui' => [
        // Navigation
        'back_to_overview' => '← Zurück zur Übersicht',
        'trash' => '🗑️ Papierkorb',
        'dashboard' => 'Dashboard',
        'anmeldungen' => 'Anmeldungen',
        'overview' => 'Übersicht',
        'warning' => '⚠️ Hinweis:',
        'info' => 'ℹ️ Information:',

        // Pagination
        'entries_per_page' => 'Einträge pro Seite:',
        'showing_entries' => 'Zeige {{from}} bis {{to}} von {{total}} Einträgen',
        'page' => 'Seite {{current}} von {{total}}',
        'no_entries' => 'Keine Einträge gefunden',

        // Buttons
        'buttons' => [
            'archive' => '📦 Archivieren',
            'delete' => '🗑️ Löschen',
            'restore' => '↩️ Wiederherstellen',
            'hard_delete' => '⚠️ Endgültig löschen',
            'excel_export' => '📥 Excel-Export',
            'excel_export_all' => '📥 Alle exportieren',
            'excel_export_selected' => '📥 Ausgewählte exportieren',
            'bulk_actions' => 'Aktionen',
            'apply' => 'Anwenden',
            'cancel' => 'Abbrechen',
            'save' => 'Speichern',
            'edit' => 'Bearbeiten',
            'view' => 'Ansehen',
            'download' => 'Herunterladen',
        ],

        // Table Headers
        'table' => [
            'id' => 'ID',
            'form' => 'Formular',
            'name' => 'Name',
            'email' => 'E-Mail',
            'status' => 'Status',
            'date' => 'Datum',
            'created_at' => 'Erstellt am',
            'updated_at' => 'Aktualisiert am',
            'deleted_at' => 'Gelöscht am',
            'actions' => 'Aktionen',
        ],

        // Filters
        'filters' => [
            'all_forms' => 'Alle Formulare',
            'all_statuses' => 'Alle Status',
            'filter_by_form' => 'Nach Formular filtern',
            'filter_by_status' => 'Nach Status filtern',
            'search' => 'Suchen',
            'reset_filters' => 'Filter zurücksetzen',
        ],

        // Detail View
        'detail' => [
            'title' => 'Anmeldungs-Details',
            'basic_info' => 'Grundinformationen',
            'form_data' => 'Formulardaten',
            'metadata' => 'Metadaten',
            'file_uploads' => 'Hochgeladene Dateien',
            'no_files' => 'Keine Dateien hochgeladen',
            'mark_as' => 'Markieren als',
            'no_data' => 'Keine Daten vorhanden',
            'yes' => 'Ja',
            'no' => 'Nein',
            'deleted_yes' => 'Ja',
        ],

        // Dashboard
        'dashboard' => [
            'title' => 'Dashboard',
            'statistics' => 'Statistiken',
            'total_anmeldungen' => 'Gesamt Anmeldungen',
            'new_anmeldungen' => '📬 Neue Anmeldungen',
            'all_anmeldungen' => '📋 Alle Anmeldungen',
            'by_form' => 'Nach Formular',
            'by_status' => 'Nach Status',
            'recent_submissions' => 'Letzte Anmeldungen',
            'auto_expunge_status' => '🗑️ Auto-Expunge Status',
            'last_expunge' => 'Letzter Lauf',
            'next_expunge' => 'Nächster Lauf',
            'entries_ready' => 'Einträge bereit zum Löschen',
            'expunge_days' => 'Löschfrist',
            'days_count' => '{{days}} Tage',
            'never_run' => 'Noch nie ausgeführt',
            'overdue' => '(überfällig)',
            'next_page_load' => 'Bei nächstem Seitenaufruf',
            'oldest_entry' => 'Ältester Eintrag',
            'expunge_auto_info' => 'Auto-Expunge läuft automatisch alle 6 Stunden bei einem Seitenaufruf.',
            'next_check' => 'Die nächste automatische Prüfung erfolgt',
            'on' => 'am',
            'at' => 'um',
            'oclock' => 'Uhr',
            'confirm_expunge' => '{{count}} Einträge werden permanent gelöscht. Fortfahren?',
            'run_expunge_now' => '🗑️ Jetzt manuell ausführen',
            'quick_actions' => '⚡ Schnellzugriff',
        ],

        // Trash
        'trash' => [
            'title' => '🗑️ Papierkorb',
            'empty' => 'ℹ️ Der Papierkorb ist leer',
            'empty_description' => 'Gelöschte Einträge erscheinen hier.',
            'warning_description' => 'Diese Einträge wurden gelöscht und sind für normale Benutzer nicht sichtbar.',
            'auto_delete_info' => 'Sie werden nach <strong>{{days}} Tagen</strong> permanent gelöscht.',
            'entry_count' => 'Anzahl: {{count}} Einträge',
            'confirm_restore' => 'Eintrag #{{id}} wiederherstellen?',
            'confirm_hard_delete' => 'ACHTUNG: Dieser Eintrag wird endgültig gelöscht und kann nicht wiederhergestellt werden. Fortfahren?',
        ],
    ],

    /**
     * Status Labels
     */
    'status' => [
        'neu' => 'Neu',
        'exportiert' => 'Exportiert',
        'in_bearbeitung' => 'In Bearbeitung',
        'akzeptiert' => 'Akzeptiert',
        'abgelehnt' => 'Abgelehnt',
        'archiviert' => 'Archiviert',
    ],

    /**
     * Bulk Actions
     */
    'bulk_actions' => [
        'select_action' => 'Aktion wählen',
        'archive' => 'Archivieren',
        'delete' => 'Löschen',
        'mark_as_neu' => 'Als "Neu" markieren',
        'mark_as_exportiert' => 'Als "Exportiert" markieren',
        'mark_as_in_bearbeitung' => 'Als "In Bearbeitung" markieren',
        'mark_as_akzeptiert' => 'Als "Akzeptiert" markieren',
        'mark_as_abgelehnt' => 'Als "Abgelehnt" markieren',
    ],

    /**
     * Excel Export Metadata
     */
    'excel' => [
        'metadata_sheet' => 'Informationen',
        'data_sheet' => 'Anmeldungen',
        'export_date' => 'Exportiert am',
        'total_entries' => 'Anzahl Einträge',
        'filter_form' => 'Formular-Filter',
        'filter_status' => 'Status-Filter',
        'filter_none' => 'Kein Filter',
        'generated_by' => 'Erstellt von',
        'system_name' => 'Schulanmeldungs-System',
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
     * Date/Time Formatting
     */
    'datetime' => [
        'format_date' => 'd.m.Y',
        'format_datetime' => 'd.m.Y H:i',
        'format_time' => 'H:i',
        'never' => 'Nie',
        'just_now' => 'Gerade eben',
        'minutes_ago' => 'vor {{minutes}} Minuten',
        'hours_ago' => 'vor {{hours}} Stunden',
        'days_ago' => 'vor {{days}} Tagen',
    ],

    /**
     * Auto-Expunge Messages
     */
    'expunge' => [
        'enabled' => 'Aktiviert ({{days}} Tage)',
        'disabled' => 'Deaktiviert',
        'last_run' => 'Letzter Lauf: {{date}}',
        'next_run' => 'Nächster Lauf: {{date}}',
        'entries_ready' => '{{count}} Einträge',
        'no_entries_ready' => '0 Einträge',
    ],

    /**
     * API Error Messages
     */
    'api' => [
        'errors' => [
            'invalid_method' => 'Invalid request method',
            'missing_form_key' => 'Missing form_key',
            'validation_failed' => 'Validation failed: {{error}}',
            'save_failed' => 'Failed to save anmeldung',
            'internal_server_error' => 'Internal server error',
        ],
    ],

    /**
     * PDF Error Messages
     */
    'pdf' => [
        'errors' => [
            'missing_token' => 'Fehlender Download-Token',
            'invalid_token' => 'Ungültiger oder abgelaufener Token',
            'not_enabled' => 'PDF-Download ist für dieses Formular nicht aktiviert',
            'download_failed_title' => 'PDF-Download nicht möglich',
            'download_failed_hint' => 'Der Link ist möglicherweise abgelaufen (gültig 30 Min.) oder ungültig.',
            'unexpected_error' => 'Ein unerwarteter Fehler ist beim PDF-Download aufgetreten. {{contact}}',
        ],
    ],
];
