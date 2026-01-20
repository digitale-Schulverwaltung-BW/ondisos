<?php
declare(strict_types=1);

/**
 * Local Message Overrides - Example Template (Frontend)
 *
 * Copy this file to messages.local.php and customize as needed.
 * The .local.php file is gitignored and safe for local changes.
 *
 * USAGE:
 * ------
 * 1. Copy this file: cp messages.example.php messages.local.php
 * 2. Edit messages.local.php with your site-specific values
 * 3. Only include messages you want to override (not the entire array)
 * 4. Changes in messages.local.php will never create git conflicts
 *
 * EXAMPLES:
 * ---------
 * Override contact information:
 *   'contact' => [
 *       'support_email' => 'sekretariat@meineschule.de',
 *       'support_text' => 'Bei Problemen: sekretariat@meineschule.de',
 *   ]
 *
 * Override error messages:
 *   'errors' => [
 *       'submission_failed' => 'Fehler beim Senden. Kontakt: {{contact}}',
 *   ]
 *
 * Override form titles:
 *   'forms' => [
 *       'bs' => [
 *           'title' => 'Bewerbung Berufsfachschule',
 *       ],
 *   ]
 */

return [
    /**
     * Contact Information (MOST IMPORTANT)
     * =====================================
     * Set your school's/organization's contact information here.
     * This will be automatically inserted into error messages via {{contact}}.
     */
    'contact' => [
        'support_email' => 'sekretariat@example.com',
        'support_text' => 'Bei Problemen: sekretariat@example.com',
    ],

    /**
     * Error Messages
     * ==============
     * Override specific error messages if needed.
     */
    // 'errors' => [
    //     'submission_failed' => 'Fehler beim Senden. Bitte kontaktieren Sie: {{contact}}',
    //     'generic_error' => 'Ein Fehler ist aufgetreten. {{contact}}',
    // ],

    /**
     * Success Messages
     * ================
     * Override success messages if needed.
     */
    // 'success' => [
    //     'submission_complete' => 'Vielen Dank! Ihre Bewerbung wurde erfolgreich übermittelt.',
    // ],

    /**
     * UI Labels
     * =========
     * Override UI labels if needed.
     */
    // 'ui' => [
    //     'prefill_link_title' => '🔗 Weitere Bewerbungen?',
    //     'prefill_link_description' => 'Nutzen Sie diesen Link für weitere Bewerbungen...',
    // ],

    /**
     * Form Titles
     * ===========
     * Override form titles and descriptions.
     */
    // 'forms' => [
    //     'bs' => [
    //         'title' => 'Bewerbung Berufsfachschule',
    //         'description' => 'Willkommen! Bitte füllen Sie das Formular aus.',
    //     ],
    //     'bk' => [
    //         'title' => 'Bewerbung Berufskolleg',
    //         'description' => 'Willkommen! Bitte füllen Sie das Formular aus.',
    //     ],
    // ],

    /**
     * Email Templates
     * ===============
     * Override email subject/body if needed.
     */
    // 'email' => [
    //     'subject_new_anmeldung' => 'Neue Bewerbung: {{formKey}}',
    //     'body_intro' => 'Eine neue Bewerbung wurde übermittelt:',
    // ],
];
