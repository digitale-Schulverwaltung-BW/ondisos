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
        // Parse attributes
        $atts = shortcode_atts([
            'form' => '',
        ], $atts, 'ondisos');

        // Validate form parameter (mandatory)
        $form_key = sanitize_key($atts['form']);
        if (empty($form_key)) {
            return $this->render_error('Error: form parameter is required. Usage: [ondisos form="bs"]');
        }

        // Check if form exists
        if (!Form_Config_Loader::ensure($form_key)) {
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

        // Load survey and theme JSON
        try {
            $survey_json = $this->load_survey_json($form_key);
            $theme_json = $this->load_theme_json($form_key);
        } catch (\Exception $e) {
            return $this->render_error('Error: ' . esc_html($e->getMessage()));
        }

        // Get form version
        $version = FormConfig::getVersion($form_key);

        // Generate WP nonce for CSRF protection
        $nonce = wp_create_nonce('ondisos_submit_' . $form_key);

        // Get AJAX URL
        $ajax_url = admin_url('admin-ajax.php');

        // Get prefill data if present
        $prefill_data = $this->get_prefill_data();

        // Enqueue assets for this form
        do_action('ondisos_enqueue_assets', $form_key);

        // Render container
        return $this->render_container($form_key, $survey_json, $theme_json, $version, $nonce, $ajax_url, $prefill_data);
    }

    /**
     * Load survey JSON for a form
     *
     * @param string $form_key Form key
     * @return string JSON string
     * @throws \RuntimeException If file not found or invalid
     */
    private function load_survey_json(string $form_key): string
    {
        $file_path = FormConfig::getFormPath($form_key);

        if (!file_exists($file_path)) {
            throw new \RuntimeException("Survey file not found: $file_path");
        }

        $json = file_get_contents($file_path);
        if ($json === false) {
            throw new \RuntimeException("Failed to read survey file: $file_path");
        }

        // Validate JSON
        json_decode($json);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in survey file: " . json_last_error_msg());
        }

        return $json;
    }

    /**
     * Load theme JSON for a form
     *
     * @param string $form_key Form key
     * @return string JSON string
     * @throws \RuntimeException If file not found or invalid
     */
    private function load_theme_json(string $form_key): string
    {
        $file_path = FormConfig::getThemePath($form_key);

        if (!file_exists($file_path)) {
            throw new \RuntimeException("Theme file not found: $file_path");
        }

        $json = file_get_contents($file_path);
        if ($json === false) {
            throw new \RuntimeException("Failed to read theme file: $file_path");
        }

        // Validate JSON
        json_decode($json);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in theme file: " . json_last_error_msg());
        }

        return $json;
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
     * @return string HTML
     */
    private function render_container(
        string $form_key,
        string $survey_json,
        string $theme_json,
        string $version,
        string $nonce,
        string $ajax_url,
        string $prefill_data
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
             data-ajax-url="<?php echo esc_url($ajax_url); ?>">
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

    private function render_error(string $message): string
    {
        return sprintf(
            '<div class="ondisos-error" style="padding: 1rem; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 4px;">%s</div>',
            esc_html($message)
        );
    }
}
