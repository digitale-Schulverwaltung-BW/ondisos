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
        'notify_email' => 'sekretariat@example.com',
        'prefill_fields' => [
            'unternehmen',
            'ansprechpartner',
            'kontakt_email'
        ],

        // PDF Download Configuration (optional)
        'pdf' => [
            'enabled' => true,
            'required' => false,  // If true, user must download PDF before continuing
            'title' => 'Berufsschule', // "Anmeldebestätigung" wird bereits im Header ausgegeben
            'download_title' => 'Bestätigung als PDF herunterladen',
            'token_lifetime' => 1800,  // 30 minutes in seconds

            // Logo (optional) - path relative to backend/templates/pdf/ or absolute
            'logo' => null,  // e.g., 'logo.png' or '/path/to/logo.png'

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
    ],
];
?>