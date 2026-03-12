<?php
/**
 * AJAX Handler
 *
 * Handles WordPress AJAX endpoints for form submission.
 * Replaces frontend/public/save.php functionality.
 *
 * @package Ondisos
 */

declare(strict_types=1);

namespace Ondisos;

use Frontend\Services\AnmeldungService;
use Frontend\Services\BackendApiClient;
use Frontend\Services\EmailService;
use Frontend\Config\FormConfig;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handler
 */
class Ajax_Handler
{
    /**
     * Constructor - register AJAX actions
     */
    public function __construct()
    {
        // Handle form submission for both logged-in and non-logged-in users
        add_action('wp_ajax_ondisos_submit', [$this, 'handle_submit']);
        add_action('wp_ajax_nopriv_ondisos_submit', [$this, 'handle_submit']);

        // iCal download endpoint (no authentication needed)
        add_action('wp_ajax_ondisos_ical', [$this, 'handle_ical']);
        add_action('wp_ajax_nopriv_ondisos_ical', [$this, 'handle_ical']);
    }

    /**
     * Handle form submission
     *
     * AJAX endpoint: admin-ajax.php?action=ondisos_submit
     */
    public function handle_submit(): void
    {
        try {
            // 1. Validate request method
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new \RuntimeException('Invalid request method', 405);
            }

            // 2. Get and validate form key
            $form_key = sanitize_key($_POST['form_key'] ?? '');

            if (empty($form_key) || !FormConfig::exists($form_key)) {
                throw new \RuntimeException('Unbekanntes Formular', 400);
            }

            // 3. Verify WordPress nonce (replaces CSRF token)
            $nonce = sanitize_text_field($_POST['nonce'] ?? '');

            if (!wp_verify_nonce($nonce, 'ondisos_submit_' . $form_key)) {
                throw new \RuntimeException('Sicherheitsvalidierung fehlgeschlagen', 403);
            }

            // 4. Get survey data (WordPress adds slashes, must remove)
            if (empty($_POST['survey_data'])) {
                throw new \RuntimeException('Keine Formulardaten empfangen', 400);
            }

            $survey_data = json_decode(
                wp_unslash($_POST['survey_data']),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (!is_array($survey_data)) {
                throw new \RuntimeException('Ungültige Formulardaten', 400);
            }

            // 5. Get metadata (optional)
            $metadata = [];
            if (!empty($_POST['meta'])) {
                $metadata = json_decode(wp_unslash($_POST['meta']), true) ?? [];
            }

            // 6. Initialize services (reuse existing frontend services!)
            $api_client = new BackendApiClient();
            $email_service = new EmailService();
            $anmeldung_service = new AnmeldungService($api_client, $email_service);

            // 7. Process submission (reuse existing logic!)
            $result = $anmeldung_service->processSubmission(
                formKey: $form_key,
                surveyData: $survey_data,
                metadata: $metadata,
                files: $_FILES
            );

            // 8. Generate prefill link on success
            if ($result['success']) {
                $prefill_link = $anmeldung_service->generatePrefillLink(
                    $form_key,
                    $survey_data,
                    wp_get_referer() ?: null
                );
                $result['prefill_link'] = $prefill_link;

                // Add iCal download info if configured for this form
                $ical_config = FormConfig::get($form_key)['ical'] ?? null;
                if ($ical_config && ($ical_config['enabled'] ?? false)) {
                    $result['ical_download'] = [
                        'enabled' => true,
                        'url'     => admin_url('admin-ajax.php')
                                     . '?action=ondisos_ical&form=' . urlencode($form_key),
                        'title'   => $ical_config['download_title'] ?? 'Termin in Kalender eintragen',
                    ];
                }

                // Generate new nonce for next submission
                $result['new_nonce'] = wp_create_nonce('ondisos_submit_' . $form_key);
            }

            // 9. Send JSON response
            wp_send_json($result, $result['success'] ? 200 : 400);

        } catch (\JsonException $e) {
            wp_send_json([
                'success' => false,
                'error' => 'Ungültige JSON-Daten'
            ], 400);

        } catch (\RuntimeException $e) {
            wp_send_json([
                'success' => false,
                'error' => $e->getMessage()
            ], $e->getCode() ?: 400);

        } catch (\Throwable $e) {
            // Log unexpected errors
            error_log('ondisos - Unexpected error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());

            wp_send_json([
                'success' => false,
                'error' => 'Ein unerwarteter Fehler ist aufgetreten'
            ], 500);
        }
    }

    /**
     * Handle iCal download
     *
     * AJAX endpoint: admin-ajax.php?action=ondisos_ical&form=<formKey>
     * Returns a .ics file (RFC 5545) for the configured event.
     */
    public function handle_ical(): void
    {
        $form_key = sanitize_key($_GET['form'] ?? '');

        if (empty($form_key) || !FormConfig::exists($form_key)) {
            status_header(404);
            exit('Formular nicht gefunden.');
        }

        $ical_config = FormConfig::get($form_key)['ical'] ?? null;

        if (!$ical_config || !($ical_config['enabled'] ?? false)) {
            status_header(404);
            exit('iCal-Download für dieses Formular nicht aktiviert.');
        }

        $date       = $ical_config['event_date']       ?? '';
        $time_start = $ical_config['event_time_start'] ?? '00:00';
        $time_end   = $ical_config['event_time_end']   ?? '00:00';

        $dt_start = str_replace('-', '', $date) . 'T' . str_replace(':', '', $time_start) . '00';
        $dt_end   = str_replace('-', '', $date) . 'T' . str_replace(':', '', $time_end)   . '00';
        $dt_stamp = gmdate('Ymd\THis\Z');
        $uid      = md5($form_key . $date . $time_start) . '@ondisos';

        $escape = function (string $text): string {
            $text = str_replace('\\', '\\\\', $text);
            $text = str_replace(["\r\n", "\r", "\n"], '\\n', $text);
            return str_replace([',', ';'], ['\\,', '\\;'], $text);
        };

        $summary     = $escape($ical_config['event_title']       ?? $form_key);
        $location    = $escape($ical_config['event_location']    ?? '');
        $description = $escape($ical_config['event_description'] ?? '');

        $ics  = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//ondisos//Schulanmeldung//DE\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:{$uid}\r\n";
        $ics .= "DTSTAMP:{$dt_stamp}\r\n";
        $ics .= "DTSTART:{$dt_start}\r\n";
        $ics .= "DTEND:{$dt_end}\r\n";
        $ics .= "SUMMARY:{$summary}\r\n";
        if ($location !== '')    { $ics .= "LOCATION:{$location}\r\n"; }
        if ($description !== '') { $ics .= "DESCRIPTION:{$description}\r\n"; }
        $ics .= "END:VEVENT\r\n";
        $ics .= "END:VCALENDAR\r\n";

        $filename = 'termin-' . preg_replace('/[^a-z0-9-]/', '-', strtolower($form_key)) . '.ics';

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Content-Length: ' . strlen($ics));

        echo $ics;
        exit;
    }
}
