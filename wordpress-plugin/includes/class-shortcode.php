<?php
/**
 * Shortcode Handler
 *
 * Handles [ondisos form="bs"] shortcode
 *
 * @package Ondisos
 */

declare(strict_types=1);

namespace Ondisos;

use Frontend\Config\FormConfig;
use Frontend\Config\SurveySource;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode handler
 */
class Shortcode
{
    /**
     * Constructor - register shortcode
     */
    public function __construct()
    {
        add_shortcode('ondisos', [$this, 'render']);
    }

    /**
     * Render shortcode
     *
     * @param array|string $atts Shortcode attributes
     * @return string HTML output
     */
    public function render($atts): string
    {
        // WordPress keeps attributes it cannot parse (e.g. form=""bs, a missing quote) as numbered entries
        $stray = is_array($atts) ? array_filter($atts, 'is_int', ARRAY_FILTER_USE_KEY) : [];

        // Parse attributes
        $atts = shortcode_atts([
            'form' => '',
        ], $atts, 'ondisos');

        // Validate form parameter (mandatory)
        $form_key = sanitize_key($atts['form']);
        if (empty($form_key)) {
            return $this->render_error(self::missing_form_message($atts['form'], $stray));
        }

        // Check if form exists
        if (!Form_Config_Loader::ensure_with_survey($form_key)) {
            return $this->render_error($this->load_failure_message($form_key));
        }

        // A form that stores nothing (db=false) and mails nobody (no valid notify_email) would discard submissions
        if (FormConfig::discardsSubmissions($form_key)) {
            error_log("Form '{$form_key}' not shown: db is false and no valid notify_email is configured, submissions would be discarded");

            return $this->render_error(
                current_user_can('manage_options')
                    ? sprintf(
                        'Error (shown to administrators only): form "%s" has db=false and no valid notify_email, so submissions would be discarded. '
                        . 'Set db to true or a notify_email in the form configuration (backend, table form_configs).',
                        $form_key
                    )
                    : 'Error: The form is currently unavailable. Please try again later.'
            );
        }

        // Survey and theme: from the backend if it delivers them, else from the files in frontend/surveys/.
        // Both come back re-encoded so they are safe inside a <script> element.
        try {
            $survey_json = SurveySource::survey($form_key);
            $theme_json = SurveySource::theme($form_key);
        } catch (\RuntimeException $e) {
            return $this->render_error('Error: ' . esc_html($e->getMessage()));
        }

        // Get form version
        $version = FormConfig::getVersion($form_key);

        // Generate WP nonce for CSRF protection
        $nonce = wp_create_nonce('ondisos_submit_' . $form_key);

        // Get AJAX URL
        $ajax_url = admin_url('admin-ajax.php');

        // Signature on a smartphone (3.2.0a): only if the form allows it
        $signature_handoff = (FormConfig::get($form_key)['signature_handoff'] ?? false) === true;

        // Get prefill data if present
        $prefill_data = $this->get_prefill_data();

        // Enqueue assets for this form
        do_action('ondisos_enqueue_assets', $form_key);

        // Render container
        return $this->render_container($form_key, $survey_json, $theme_json, $version, $nonce, $ajax_url, $prefill_data, $signature_handoff);
    }

    /**
     * Get prefill data from URL parameter
     *
     * Supports: ?prefill=base64_encoded_json
     *
     * @return string Base64 encoded JSON or empty string
     */
    private function get_prefill_data(): string
    {
        if (!isset($_GET['prefill'])) {
            return '';
        }

        $prefill = sanitize_text_field($_GET['prefill']);

        // Validate base64 format
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $prefill)) {
            return '';
        }

        // Try to decode and validate JSON
        $decoded = base64_decode($prefill, true);
        if ($decoded === false) {
            return '';
        }

        json_decode($decoded);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return '';
        }

        return $prefill;
    }

    /**
     * Render survey container
     *
     * @param string $form_key Form key
     * @param string $survey_json Survey JSON
     * @param string $theme_json Theme JSON
     * @param string $version Form version
     * @param string $nonce WP nonce
     * @param string $ajax_url AJAX URL
     * @param string $prefill_data Prefill data (base64)
     * @param bool $signature_handoff Offer signing on a smartphone (nonce, relay URL and texts as data attributes)
     * @return string HTML
     */
    private function render_container(
        string $form_key,
        string $survey_json,
        string $theme_json,
        string $version,
        string $nonce,
        string $ajax_url,
        string $prefill_data,
        bool $signature_handoff = false
    ): string {
        $container_id = 'ondisos-container-' . esc_attr($form_key);
        $survey_script_id = $container_id . '-survey-json';
        $theme_script_id = $container_id . '-theme-json';

        ob_start();
        ?>
        <div id="<?php echo $container_id; ?>"
             class="ondisos-survey-container"
             data-form-key="<?php echo esc_attr($form_key); ?>"
             data-version="<?php echo esc_attr($version); ?>"
             data-survey-json-id="<?php echo esc_attr($survey_script_id); ?>"
             data-theme-json-id="<?php echo esc_attr($theme_script_id); ?>"
             data-prefill="<?php echo esc_attr($prefill_data); ?>"
             data-nonce="<?php echo esc_attr($nonce); ?>"
             data-ajax-url="<?php echo esc_url($ajax_url); ?>"<?php if ($signature_handoff) : ?>
             data-signature-handoff="1"
             data-signature-nonce="<?php echo esc_attr(wp_create_nonce(Signature_Handler::NONCE_ACTION)); ?>"
             data-signature-url="<?php echo esc_url(Signature_Handler::relay_url()); ?>"
             data-signature-messages="<?php echo esc_attr((string)wp_json_encode(Signature_Handler::messages(), JSON_UNESCAPED_UNICODE)); ?>"<?php endif; ?>>
            <div class="ondisos-loading">
                <p>Formular wird geladen...</p>
            </div>
        </div>

        <!-- Survey JSON (UTF-8 safe) -->
        <script type="application/json" id="<?php echo esc_attr($survey_script_id); ?>">
        <?php echo $survey_json; ?>
        </script>

        <!-- Theme JSON (UTF-8 safe) -->
        <script type="application/json" id="<?php echo esc_attr($theme_script_id); ?>">
        <?php echo $theme_json; ?>
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Render error message
     *
     * @param string $message Error message
     * @return string HTML
     */
    /**
     * Message for a form that could not be loaded.
     *
     * Visitors get a neutral text (no internal addresses); administrators see what is actually wrong
     * and where to fix it. The technical reason is also in the PHP error log.
     */
    private function load_failure_message(string $form_key): string
    {
        $failure  = Form_Config_Loader::failure($form_key);
        $reason   = $failure['reason'] ?? 'not_found';
        $is_admin = current_user_can('manage_options');

        if ($reason === 'not_found') {
            return $is_admin
                ? sprintf(
                    'Error: Unknown form "%s" for tenant "%s". Check the form key in the shortcode and the form configuration in the backend.',
                    $form_key,
                    Form_Config_Loader::tenant_slug()
                )
                : sprintf('Error: Unknown form "%s".', $form_key);
        }

        if (!$is_admin) {
            return 'Error: The form is currently unavailable. Please try again later.';
        }

        $prefix = 'Error (shown to administrators only): ';
        $url    = $failure['backend_url'] ?? '';

        return match ($reason) {
            'unreachable' => sprintf(
                '%sthe ondisos backend is not reachable at %s (%s). Check "Backend API URL" under Settings → Ondisos. '
                . 'Inside a Docker container "localhost" is the container itself.',
                $prefix, $url, $failure['detail'] ?? ''
            ),
            'unauthorized' => sprintf(
                '%sthe backend rejected tenant "%s" (unknown or inactive). Check "Tenant-Slug" under Settings → Ondisos and the tenant in the backend.',
                $prefix, Form_Config_Loader::tenant_slug()
            ),
            default => sprintf(
                '%sthe backend at %s answered unexpectedly (%s). Check that "Backend API URL" points at the ondisos API (…/api).',
                $prefix, $url, $failure['detail'] ?? ''
            ),
        };
    }

    /**
     * Error text for a missing or unusable form attribute.
     *
     * A broken quotation mark (form=""bs, form="bs) is the usual cause, so say what was received
     * and that the quotes are to blame instead of claiming the parameter is absent.
     *
     * @param mixed $form  value of the form attribute (may be empty)
     * @param array<int,mixed> $stray attributes WordPress could not parse
     */
    public static function missing_form_message($form, array $stray): string
    {
        $usage = 'Usage: [ondisos form="bs"]';
        if ($stray !== []) {
            return 'Error: the shortcode could not be read, probably a missing or doubled quotation mark. Received: '
                . implode(' ', array_map('strval', $stray)) . '. ' . $usage;
        }
        if (trim((string)$form) !== '') {
            return 'Error: "' . trim((string)$form) . '" is not a valid form key (lowercase letters, digits, hyphen and underscore only). ' . $usage;
        }

        return 'Error: form parameter is required. ' . $usage;
    }

    private function render_error(string $message): string
    {
        return sprintf(
            '<div class="ondisos-error" style="padding: 1rem; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 4px;">%s</div>',
            esc_html($message)
        );
    }
}
