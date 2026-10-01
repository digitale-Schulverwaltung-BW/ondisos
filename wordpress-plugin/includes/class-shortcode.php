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
        if (!Form_Config_Loader::ensure_with_survey($form_key)) {
            return $this->render_error('Error: Unknown form "' . esc_html($form_key) . '" (or backend unavailable)');
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

        // Get prefill data if present
        $prefill_data = $this->get_prefill_data();

        // Enqueue assets for this form
        do_action('ondisos_enqueue_assets', $form_key);

        // Render container
        return $this->render_container($form_key, $survey_json, $theme_json, $version, $nonce, $ajax_url, $prefill_data);
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
    private function render_error(string $message): string
    {
        return sprintf(
            '<div class="ondisos-error" style="padding: 1rem; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 4px;">%s</div>',
            esc_html($message)
        );
    }
}
