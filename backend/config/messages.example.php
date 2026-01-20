<?php
declare(strict_types=1);

/**
 * Local Message Overrides - Example Template
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
 * Override UI labels:
 *   'ui' => [
 *       'dashboard' => 'Mein Dashboard',
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
    // ],

    /**
     * UI Labels
     * =========
     * Override UI labels if needed.
     */
    // 'ui' => [
    //     'anmeldungen' => 'Bewerbungen',
    //     'dashboard' => 'Startseite',
    // ],

    /**
     * Status Labels
     * =============
     * Override status labels if needed.
     */
    // 'status' => [
    //     'neu' => 'Unbearbeitet',
    //     'exportiert' => 'Heruntergeladen',
    // ],

    /**
     * Excel Export
     * ============
     * Override Excel export metadata if needed.
     */
    // 'excel' => [
    //     'system_name' => 'Meine Schule Anmeldungssystem',
    // ],
];
