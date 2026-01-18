<?php
/**
 * AJAX Handler
 *
 * Handles WordPress AJAX endpoints for form submission.
 * Replaces frontend/public/save.php functionality.
 *
 * @package Anmeldung_Forms
 */

declare(strict_types=1);

namespace Anmeldung_Forms;

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
        add_action('wp_ajax_anmeldung_submit', [$this, 'handle_submit']);
        add_action('wp_ajax_nopriv_anmeldung_submit', [$this, 'handle_submit']);
    }

    /**
     * Handle form submission
     *
     * AJAX endpoint: admin-ajax.php?action=anmeldung_submit
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

            if (!wp_verify_nonce($nonce, 'anmeldung_submit_' . $form_key)) {
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
                    $survey_data
                );
                $result['prefill_link'] = $prefill_link;

                // Generate new nonce for next submission
                $result['new_nonce'] = wp_create_nonce('anmeldung_submit_' . $form_key);
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
            error_log('Anmeldung Forms - Unexpected error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());

            wp_send_json([
                'success' => false,
                'error' => 'Ein unerwarteter Fehler ist aufgetreten'
            ], 500);
        }
    }
}
