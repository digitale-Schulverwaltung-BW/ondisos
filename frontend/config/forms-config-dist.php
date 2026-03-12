<?php
/**
 * Form Configuration Template
 *
 * Copy this file to forms-config.php and customize as needed.
 */

return [
    'bs' => [
        'db' => true,
        'form'  => 'bs.json',
        'theme' => 'survey_theme.json',
        'version' => '2026-01-v2',
        // Notification email(s) - supports single address or comma-separated list
        'notify_email' => 'sekretariat@example.com, lehrer@example.com',

        // Fields to prefill in the form when using a prefill link
        // Note: Field names must match exactly with the form definition (bs.json)
        'prefill_fields' => [
            'Ausbildungsbetrieb',  // Company name - useful for registering multiple apprentices from the same company
            'Ausbilder',           // Ausbilder name
            'AusbStrasse',         // Straße des Ausbildungsbetriebs
            'AusbHausnummer',      // Hausnummer
            'AusbPLZ',             // PLZ
            'AusbOrt',             // Ort
            'AusbTel',             // Telefon
            'AusbEmail'            // E-Mail Adresse
        ],

        // iCal Download Configuration (optional) - uncomment to enable
        // 'ical' => [
        //     'enabled'          => true,
        //     'download_title'   => 'Termin in Kalender eintragen',
        //     'event_title'      => 'Einschulung Berufliches Schulzentrum',
        //     'event_date'       => '2026-09-01',
        //     'event_time_start' => '08:00',
        //     'event_time_end'   => '12:00',
        //     'event_location'   => 'Berufliches Schulzentrum, Musterstraße 1, 12345 Musterstadt',
        //     'event_description' => 'Einschulungsveranstaltung.',
        // ],

        // PDF Download Configuration (optional)
        'pdf' => [
            'enabled' => true,
            'required' => false,  // If true, user must download PDF before continuing
            'title' => 'Berufsschule', // "Anmeldebestätigung" wird bereits im Header ausgegeben
            'download_title' => 'Bestätigung als PDF herunterladen',
            'token_lifetime' => 1800,  // 30 minutes in seconds

            // Logo (optional) - path relative to backend/templates/pdf/assets or absolute
            'logo' => false,  // e.g., 'logo.png' or '/path/to/logo.png'

            // PDF Header
            'header_title' => 'Anmeldebestätigung Berufliches Schulzentrum',

            // Intro text (optional)
            'intro_text' => 'Vielen Dank für Ihre Anmeldung.',

            // Footer text (optional)
            'footer_text' => 'Bei Fragen wenden Sie sich bitte an unser Sekretariat.',

            // Field filtering
            'include_fields' => 'all',  // 'all' or array of field names to include
            'exclude_fields' => ['consent_datenschutz', 'consent_agb'],

            // Custom sections (optional)
            'pre_sections' => [
                // Sections shown BEFORE the data table
                // ['title' => 'Section Title', 'content' => 'Section content...'],
            ],
            'post_sections' => [
                // Sections shown AFTER the data table
                // ['title' => 'Next Steps', 'content' => 'What happens next...'],
            ],
        ],
    ],

    'bk' => [
        'db' => true,
        'form'  => 'bk.json',
        'theme' => 'survey_theme.json',
        'version' => '2026-01-v2',
        'notify_email' => '',

        // PDF disabled for this form
        // 'pdf' => ['enabled' => false],
    ],

    'ausbildernachmittag' => [
        'db' => false,
        'form'  => 'ausbildernachmittag.json',
        'theme' => 'survey_theme.json',
        'version' => '2026-01-v2',
        'notify_email' => 'ausbildernachmittag@example.com',

        // iCal Download Configuration (optional)
        // Allows registrants to add the event to their calendar after submitting.
        'ical' => [
            'enabled'          => true,
            'download_title'   => 'Termin in Kalender eintragen',
            'event_title'      => 'Ausbildernachmittag Berufliches Schulzentrum',
            'event_date'       => '2026-04-15',   // YYYY-MM-DD
            'event_time_start' => '14:00',         // HH:MM (24h)
            'event_time_end'   => '17:00',
            'event_location'   => 'Berufliches Schulzentrum, Musterstraße 1, 12345 Musterstadt',
            'event_description' => 'Ausbildernachmittag für Ausbildungsbetriebe.',
        ],
    ],
    'prefill_demo' => [
        'db' => false,
        'form'  => 'prefill.json',
        'theme' => 'survey_theme.json',
        'version' => '2026-01-v2',
        'prefill_fields' => [
            'Name',      // Gleiche Anmeldung mit dem selben Namen
            'Telefon'    // und der selben Telefonnummer (aber anderer Mail-Adresse. Sinnfreies Beispiel zu Demo-Zwecken)
        ],        
    ],
    'pdf_download_demo' => [
        'db' => true,
        'form'  => 'pdf.json', // Achtung: Ohne Abspeichern in DB kein Download!
        'theme' => 'survey_theme.json',
        'version' => '2026-01-v2',
        // PDF Download Configuration (optional)
        'pdf' => [
            'enabled' => true,
            'required' => false,  // If true, user must download PDF before continuing
            'title' => 'Demo-Anmeldung', // "Anmeldebestätigung" wird bereits im Header ausgegeben
            'download_title' => 'Bestätigung als PDF herunterladen',
            'token_lifetime' => 1800,  // 30 minutes in seconds
            'logo' => 'logo.png',  // e.g., 'logo.png' or '/path/to/logo.png'
            'header_title' => 'Anmeldebestätigung Musterschule',
            'intro_text' => 'Vielen Dank für Ihre Anmeldung.',
            'footer_text' => 'Bei Fragen wenden Sie sich bitte an unser Sekretariat.',
            'include_fields' => 'all',  // 'all' or array of field names to include
            'exclude_fields' => ['consent_datenschutz', 'consent_agb'],
            'pre_sections' => [
                ['title' => 'Beispiel-Anmeldung',
                'content' => 'Diese Anmeldung dient der Demonstration der PDF-Bestätigung.'],
            ],
            'post_sections' => [
                ['title' => 'Nächste Schritte',
                'content' => 'Diese Anmeldung NICHT ausdrucken, abheften und keine weiteren Unterlagen einreichen.'],
            ],
        ],        
    ],        
];
?>