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
        'formular_name_too_long' => 'Formularname ist zu lang (max. 50 Zeichen)',
        'invalid_formular_name' => 'Ungültiger Formularname (nur Buchstaben, Zahlen, _ und - erlaubt)',
        'invalid_filename' => 'Ungültiger Dateiname (nur Buchstaben, Zahlen, _ und - erlaubt)',
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
        'invalid_data' => 'Ungültige Formulardaten',
        'generic_error' => 'Ein unerwarteter Fehler ist aufgetreten. {{contact}}',
        'database_error' => 'Datenbankfehler. {{contact}}',
        'not_found' => 'Eintrag nicht gefunden',
        'already_deleted' => 'Eintrag wurde bereits gelöscht',
        'restore_failed' => 'Wiederherstellen fehlgeschlagen',
        'delete_failed' => 'Löschen fehlgeschlagen',
        'no_entries_selected' => 'Bitte wählen Sie mindestens einen Eintrag aus',
        'expunge_failed' => 'Expunge fehlgeschlagen',
        'invalid_id' => 'Ungültige ID',
        'invalid_status' => 'Ungültiger Status',
        'status_update_failed' => 'Status-Änderung fehlgeschlagen',
    ],

    /**
     * Success Messages
     */
    'success' => [
        'bulk_action_completed' => '{{count}} Einträge erfolgreich {{action}}',
        'restored' => '✓ Wiederhergestellt! Eintrag #{{id}} wurde wiederhergestellt.',
        'deleted' => '⚠️ Permanent gelöscht! Eintrag #{{id}} wurde permanent aus der Datenbank entfernt.',
        'status_updated' => 'Status von Eintrag #{{id}} erfolgreich auf "{{status}}" geändert',
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
            'mark_in_progress' => 'In Bearbeitung',
            'accept' => 'Akzeptieren',
            'reject' => 'Ablehnen',
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
            'confirm_delete' => 'Anmeldung wirklich löschen? Diese Aktion kann rückgängig gemacht werden (Papierkorb).',
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
            'rate_limit' => 'Zu viele Anfragen. Bitte versuchen Sie es später erneut.',
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
    /**
     * Form editor (3.1): forms.php, form_edit.php
     * Labels and help texts of the config options are looked up as forms.fields.<path>.label / .help.
     */
    'help' => [
        'title' => 'Hilfe zu dieser Seite',
    ],

    'forms' => [
        'pdf_preview' => [
            'button' => 'PDF-Vorschau',
            'help' => 'Zeigt die PDF-Bestätigung mit Beispielangaben (Feldname, 1.1.2000, 1) und Ihren aktuellen, noch nicht gespeicherten Eingaben.',
            'no_survey' => 'Für die PDF-Vorschau braucht das Formular eine Survey im Backend. Bitte zuerst im Survey-Editor eine Survey einfügen.',
            'failed' => 'Die PDF-Vorschau konnte nicht erstellt werden.',
        ],
        'back_to_form' => '← Zurück zum Formular',
        'logo' => [
            'title' => 'Logo der Schule',
            'help' => 'Das Logo erscheint oben in den PDF-Bestätigungen aller Formulare dieser Schule. PNG oder JPEG, höchstens 2 MB; ein transparenter Hintergrund bleibt bei PNG erhalten.',
            'none' => 'Noch kein Logo hochgeladen',
            'file' => 'Logo-Datei',
            'upload' => 'Logo hochladen',
            'replace' => 'Logo ersetzen',
            'remove' => 'Logo entfernen',
            'confirm_remove' => 'Das Logo wirklich entfernen?',
            'saved' => 'Das Logo wurde gespeichert. Es erscheint ab sofort in den PDF-Bestätigungen.',
            'removed' => 'Das Logo wurde entfernt.',
            'in_form' => 'Logo der Schule',
            'in_form_help' => 'Wird für alle Formulare der Schule verwendet und auf der Seite „Formulare" geändert.',
            'in_form_none' => 'Noch kein Logo hochgeladen.',
            'in_form_link' => 'Logo ändern',
        ],
        'title' => 'Formulare',
        'choose_tenant' => 'Bitte oben rechts einen Tenant wählen: Formulare gehören immer zu genau einer Schule.',
        'none_yet' => 'Es gibt noch kein Formular.',
        'back' => '← Zurück zu den Formularen',
        'edit' => 'Bearbeiten',
        'edit_title' => 'Formular „{{form}}"',
        'save' => 'Speichern',
        'cancel' => 'Abbrechen',
        'saved' => 'Gespeichert.',
        'created' => 'Formular „{{form}}" wurde angelegt.',
        'deleted' => 'Formular „{{form}}" wurde gelöscht.',
        'restored' => 'Der frühere Stand wurde wiederhergestellt.',
        'revision_not_found' => 'Diesen Stand gibt es nicht.',
        'not_found' => 'Dieses Formular gibt es nicht.',
        'draft' => 'Entwurf',
        'reload' => 'Aktuellen Stand laden',
        'conflict' => 'Das Formular wurde inzwischen von jemand anderem geändert. Ihre Änderungen wurden nicht gespeichert.',
        'fix_errors' => 'Bitte die markierten Felder korrigieren. Es wurde nichts gespeichert.',
        'csrf_failed' => 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.',
        'platform_only' => 'Nur für Plattform-Administratoren änderbar.',
        'one_per_line' => 'Eine Adresse pro Zeile.',
        'filter_all' => 'Alle Felder',
        'filter_list' => 'Nur diese Felder',
        'names_free_text' => 'Ein Feldname pro Zeile. (Sobald die Survey im Backend gespeichert ist, erscheint hier eine Auswahl.)',
        'not_in_survey' => 'nicht in der Survey',
        'section_title' => 'Überschrift',
        'section_content' => 'Text',
        'diagnose' => 'Diagnose: gespeicherte Konfiguration (JSON, nur lesbar)',

        'col' => [
            'key' => 'Schlüssel',
            'version' => 'Version',
            'survey' => 'Survey',
            'submissions' => 'Anmeldungen',
        ],
        'source' => [
            'database' => 'Backend',
            'file' => 'Datei im Frontend',
            'file_hint' => 'Die Survey kommt aus einer Datei im Frontend.',
        ],
        'new' => [
            'title' => 'Neues Formular anlegen',
            'key' => 'Schlüssel',
            'key_help' => 'Kleinbuchstaben, Ziffern, _ und -. Der Schlüssel steht in der Adresse (?form=…) und im WordPress-Shortcode [ondisos form="…"] und lässt sich später nicht mehr ändern.',
            'submit' => 'Anlegen',
        ],
        'survey' => [
            'title' => 'Survey (Fragen des Formulars)',
            'file' => 'Datei',
            'edit' => 'Survey bearbeiten',
            'fields' => '{{count}} Felder',
            'import_hint' => 'Diese Survey liegt als Datei im Frontend. Mit „php import-surveys.php" lässt sie sich ins Backend übernehmen (siehe docs/betreiber/MIGRATION-3.1.md).',
        ],
        'history' => [
            'title' => 'Verlauf',
            'when' => 'Wann',
            'what' => 'Was',
            'note' => 'Anmerkung',
            'who' => 'Wer',
            'restore' => 'Wiederherstellen',
            'confirm' => 'Diesen früheren Stand wiederherstellen? Der aktuelle Stand bleibt im Verlauf erhalten.',
            'kind' => ['config' => 'Konfiguration', 'survey' => 'Survey', 'theme' => 'Theme'],
        ],
        'delete' => [
            'title' => 'Formular löschen',
            'help' => 'Das Formular ist dann nicht mehr erreichbar. Der Verlauf bleibt erhalten.',
            'confirm' => 'Dieses Formular wirklich löschen?',
            'button' => 'Löschen',
            'refused' => 'Zu diesem Formular gibt es {{count}} Anmeldung(en); es kann nicht gelöscht werden.',
        ],
        'copy' => [
            'title' => 'Formulare von einem anderen Tenant übernehmen',
            'from' => 'Von Tenant',
            'overwrite' => 'Vorhandene Formulare ersetzen',
            'submit' => 'Übernehmen',
            'help' => 'Kopiert Konfiguration, Survey und Theme. Nicht kopiert werden Empfänger-Adressen, das PDF-Logo, Anmeldungen, Entwürfe, Verlauf und Schlüssel.',
            'failed' => 'Das Kopieren ist fehlgeschlagen. Es wurde nichts kopiert.',
            'report_title' => 'Kopiert von „{{from}}" nach „{{to}}"',
            'no_forms' => 'Der Quell-Tenant hat keine Formulare.',
            'recipient_missing' => 'Bitte prüfen: Empfänger fehlt (wurde bewusst nicht kopiert).',
            'hidden' => 'Das Formular wird erst angezeigt, wenn ein Empfänger eingetragen ist.',
            'review' => 'Bitte Texte prüfen (Name/Kontakt der anderen Schule?):',
            'next' => 'Nächster Schritt: unter „Formulare" je Formular den Empfänger der neuen Schule eintragen.',
            'status' => [
                'copied' => 'kopiert',
                'overwritten' => 'ersetzt',
                'skipped' => 'übersprungen (gibt es schon)',
                'missing' => 'nicht gefunden',
                'invalid' => 'nicht kopiert (ungültig)',
            ],
        ],
        'groups' => [
            'general' => 'Allgemein',
            'email' => 'Benachrichtigungs-E-Mail',
            'pdf' => 'PDF-Bestätigung',
            'ical' => 'Kalender-Download (iCal)',
            'files' => 'Dateien',
            'info' => 'Info',
        ],
        'fields' => [
            'version' => ['label' => 'Version', 'help' => 'Wird bei jeder Anmeldung mitgespeichert, um spätere Änderungen am Formular nachvollziehen zu können.'],
            'db' => ['label' => 'Anmeldungen im Backend speichern', 'help' => 'Dringend empfohlen: Nur gespeicherte Anmeldungen lassen sich exportieren und als PDF herunterladen.'],
            'notify_email' => ['label' => 'Benachrichtigung an', 'help' => 'Bei jeder Anmeldung geht eine E-Mail an diese Adressen.'],
            'prefill_fields' => ['label' => 'Felder für Vorausfüll-Links', 'help' => 'Diese Felder werden in den Link zum Vorausfüllen übernommen (z. B. für Betriebe, die mehrere Personen anmelden).'],
            'form' => ['label' => 'Survey-Datei', 'help' => 'Name der Survey, aus der das Formular aufgebaut wird (z. B. bs.json).'],
            'theme' => ['label' => 'Theme-Datei', 'help' => 'Name des Farb-/Schrift-Themes (z. B. survey_theme.json).'],
            'email' => [
                'intro_template' => ['label' => 'Einleitungstext der E-Mail', 'help' => 'Platzhalter wie {Vorname} werden durch die Eingaben ersetzt. Leer: Standardtext.'],
            ],
            'ical' => [
                'enabled' => ['label' => 'Kalender-Download anbieten', 'help' => 'Nach dem Absenden kann ein Termin in den Kalender übernommen werden.'],
                'download_title' => ['label' => 'Beschriftung des Download-Links'],
                'event_title' => ['label' => 'Titel des Termins'],
                'event_date' => ['label' => 'Datum'],
                'event_time_start' => ['label' => 'Beginn'],
                'event_time_end' => ['label' => 'Ende'],
                'event_location' => ['label' => 'Ort'],
                'event_description' => ['label' => 'Beschreibung'],
            ],
            'pdf' => [
                'enabled' => ['label' => 'PDF-Bestätigung anbieten', 'help' => 'Nach dem Absenden kann die Anmeldung als PDF heruntergeladen werden (setzt „Anmeldungen im Backend speichern" voraus).'],
                'required' => ['label' => 'Download vor dem Abschluss verlangen'],
                'title' => ['label' => 'Titel'],
                'download_title' => ['label' => 'Beschriftung des Download-Buttons'],
                'token_lifetime' => ['label' => 'Gültigkeit des Download-Links (Sekunden)', 'help' => '60 bis 86400; üblich: 1800 (30 Minuten).'],
                'header_title' => ['label' => 'Überschrift im PDF'],
                'intro_text' => ['label' => 'Einleitungstext'],
                'footer_text' => ['label' => 'Fußzeile'],
                'include_fields' => ['label' => 'Felder im PDF', 'help' => 'Nur wirksam, wenn „Nur diese Felder" gewählt ist.'],
                'exclude_fields' => ['label' => 'Felder, die nicht im PDF erscheinen'],
                'pre_sections' => ['label' => 'Abschnitte vor den Angaben'],
                'post_sections' => ['label' => 'Abschnitte nach den Angaben'],
                'logo' => ['label' => 'Logo (Dateiname)', 'help' => 'Dateiname im Verzeichnis templates/pdf/assets/ des Backends.'],
            ],
        ],
    ],
    /**
     * Survey editor (3.1): form_survey.php
     */
    'survey_editor' => [
        'title' => 'Survey von „{{form}}"',
        'breadcrumb' => 'Survey',
        'live' => 'Veröffentlicht',
        'line' => 'Zeile {{line}}',
        'column' => '(Spalte {{column}})',
        'editor' => 'Survey (JSON)',
        'editor_help' => 'Hier das JSON aus dem SurveyJS-Creator einfügen (siehe Anleitung unten). Beim Prüfen und Speichern wird nichts veröffentlicht.',
        'copy' => 'JSON kopieren',
        'not_in_backend' => 'Diese Survey ist noch nicht im Backend gespeichert: das Formular nutzt die Datei im Frontend. Mit der ersten Veröffentlichung übernimmt das Backend.',
        'draft_stale' => 'Die veröffentlichte Survey hat sich seit diesem Entwurf geändert.',
        'draft_saved' => 'Entwurf gespeichert. Das veröffentlichte Formular ist unverändert.',
        'draft_discarded' => 'Der Entwurf wurde verworfen.',
        'discard_confirm' => 'Den Entwurf wirklich verwerfen?',
        'published' => 'Veröffentlicht: das Formular zeigt ab sofort diese Fassung.',
        'version_not_saved' => 'Die Version konnte nicht geändert werden:',
        'conflict' => 'Die veröffentlichte Survey wurde inzwischen von jemand anderem geändert. Ihr Entwurf wurde gespeichert, aber nicht veröffentlicht. Bitte den Unterschied unten prüfen und erneut veröffentlichen.',
        'upload_failed' => 'Die Datei konnte nicht gelesen werden.',
        'upload_too_big' => 'Die Datei ist zu groß (höchstens 512 KB).',
        'upload_loaded' => 'Die Datei wurde in den Editor geladen und noch nicht gespeichert.',
        'btn' => [
            'check' => 'Prüfen',
            'save_draft' => 'Entwurf speichern',
            'save_and_preview' => 'Entwurf speichern & Vorschau',
            'preview' => 'Vorschau',
            'publish' => 'Veröffentlichen',
            'upload' => 'Datei laden',
            'discard' => 'Entwurf verwerfen',
        ],
        'publish' => [
            'title' => 'Veröffentlichen',
            'help' => 'Der Text aus dem Editor wird geprüft, gespeichert und sofort für alle Besucher sichtbar. Der bisherige Stand bleibt im Verlauf und lässt sich wiederherstellen. Änderungen wirken nur auf neue Anmeldungen.',
            'version' => 'Neue Formular-Version (optional)',
            'version_help' => 'Aktuell: {{current}}. Wird bei jeder Anmeldung mitgespeichert. Leer lassen, um sie nicht zu ändern.',
            'note' => 'Anmerkung für den Verlauf (optional)',
            'confirm' => 'Jetzt veröffentlichen? Das Formular ändert sich sofort für alle Besucher.',
        ],
        'report' => [
            'ok' => 'Prüfung: keine Fehler',
            'errors' => 'Prüfung: bitte korrigieren',
            'nothing_saved' => 'Es wurde nichts gespeichert.',
            'warnings' => 'Hinweise',
        ],
        'required' => [
            'title' => 'Pflichtfelder für Ondisos',
            'name' => 'Name',
            'email' => 'E-Mail-Adresse',
            'ok' => 'Feld „{{field}}" vorhanden, Pflichtfeld',
            'not_required' => 'Feld „{{field}}" vorhanden, aber nicht als Pflichtfeld markiert',
            'missing' => 'Es fehlt ein Feld dafür',
            'help' => 'Gespeicherte Anmeldungen brauchen Name (Feld „Name") und E-Mail (Feld „email"): sie erscheinen in der Übersicht, im Excel-Export und in Benachrichtigungen.',
            'not_needed' => 'Dieses Formular speichert nicht im Backend; Name und E-Mail sind dafür nicht nötig.',
        ],
        'fields' => [
            'title' => 'Änderungen an den Feldern',
            'removed' => 'Entfernte oder umbenannte Felder:',
            'removed_hint' => 'Diese Felder fehlen künftig in neuen Anmeldungen, im Excel-Export, in Vorausfüll-Links und im PDF. Bereits gespeicherte Anmeldungen behalten ihre Daten.',
            'added' => 'Neue Felder:',
            'type_changed' => 'Anderer Typ:',
            'none' => 'Die Felder sind unverändert.',
        ],
        'diff' => [
            'title' => 'Unterschied zur veröffentlichten Fassung',
            'identical' => 'Keine Änderung gegenüber der veröffentlichten Fassung.',
            'too_large' => 'Zu viele Änderungen für die Anzeige.',
        ],
        'howto' => [
            'title' => 'So kommt eine Survey hierher',
            '1' => 'Im SurveyJS-Creator (kostenlose Online-Version) das Formular bearbeiten.',
            '2' => 'Dort den Reiter „JSON Editor" öffnen, den gesamten Text kopieren.',
            '3' => 'Hier im Editor alles ersetzen, auf „Prüfen" klicken, Hinweise lesen, dann „Entwurf speichern" und „Veröffentlichen".',
            '4' => 'Für eine Änderung am bestehenden Formular: den Text aus dem Editor hier („JSON kopieren") im Creator unter „JSON Editor" einfügen.',
            'license' => 'Der Creator gehört nicht zu Ondisos; die Online-Version von surveyjs.io wird von SurveyJS betrieben.',
        ],
    ],
    /**
     * Survey preview (3.1): form_preview.php
     */
    'preview' => [
        'title' => 'Vorschau',
        'heading' => 'Vorschau von „{{form}}"',
        'back_to_editor' => 'Zurück zum Survey-Editor',
        'source' => 'Welche Fassung',
        'draft' => 'Entwurf',
        'live' => 'Veröffentlicht',
        'width' => 'Breite',
        'phone' => 'Handy',
        'tablet' => 'Tablet',
        'desktop' => 'Desktop',
        'showing_draft' => 'Gezeigt wird der Entwurf. Besucher sehen noch die veröffentlichte Fassung.',
        'showing_live' => 'Gezeigt wird die veröffentlichte Fassung.',
        'invalid_title' => 'Die Vorschau wird nicht angezeigt.',
        'invalid' => 'Diese Survey enthält Inhalte, die nicht angezeigt werden dürfen. Bitte im Survey-Editor korrigieren:',
        'nothing' => 'Zu diesem Formular gibt es im Backend noch keine Survey, die sich anzeigen ließe (sie liegt als Datei im Frontend).',
        'to_editor' => 'Survey im Editor einfügen',
        'no_theme' => 'Das Theme (Farben, Schrift) liegt als Datei im Frontend und fehlt in der Vorschau. Das Formular sieht für Besucher also etwas anders aus.',
        'note' => 'Vorschau: Eingaben werden nicht gespeichert, „Abschicken" sendet nichts.',
        'completed' => 'Vorschau: Das Formular wurde nicht abgeschickt, es wird nichts gespeichert.',
    ],
];
