<?php
/**
 * Assets Handler
 *
 * Handles enqueueing of SurveyJS libraries, fonts, and custom scripts.
 * Only loads assets on pages with [anmeldung] shortcode.
 *
 * @package Anmeldung_Forms
 */

declare(strict_types=1);

namespace Anmeldung_Forms;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Assets handler
 */
class Assets
{
    /**
     * Frontend assets URL (relative to plugin or via symlink)
     */
    private string $frontend_assets_url;

    /**
     * Constructor - register hooks
     */
    public function __construct()
    {
        // Calculate frontend assets URL
        // Since we're using symlinks, we need to go up one level from wordpress-plugin
        $this->frontend_assets_url = plugin_dir_url(dirname(ANMELDUNG_PLUGIN_FILE)) . 'frontend/public/assets/';

        // Enqueue assets on frontend
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        // Hook into shortcode rendering to enable assets
        add_action('anmeldung_enqueue_assets', [$this, 'enable_assets']);
    }

    /**
     * Flag to track if assets should be loaded
     */
    private bool $should_load_assets = false;

    /**
     * Enable assets loading (called from shortcode)
     *
     * @param string $form_key Form key
     */
    public function enable_assets(string $form_key): void
    {
        $this->should_load_assets = true;
    }

    /**
     * Enqueue all assets
     *
     * Only loads if shortcode is present in content
     */
    public function enqueue_assets(): void
    {
        // Check if current post has shortcode
        global $post;
        if (!$post || !has_shortcode($post->post_content, 'anmeldung')) {
            return;
        }

        // Enable assets
        $this->should_load_assets = true;

        // Enqueue SurveyJS core CSS
        wp_enqueue_style(
            'surveyjs-core',
            $this->frontend_assets_url . 'survey-core.fontless.min.css',
            [],
            ANMELDUNG_PLUGIN_VERSION
        );

        // Add custom fonts (DSGVO-compliant)
        $this->add_custom_fonts();

        // Enqueue SurveyJS core JS
        wp_enqueue_script(
            'surveyjs-core',
            $this->frontend_assets_url . 'survey.core.min.js',
            [],
            ANMELDUNG_PLUGIN_VERSION,
            true // Load in footer
        );

        // Enqueue SurveyJS UI
        wp_enqueue_script(
            'surveyjs-ui',
            $this->frontend_assets_url . 'survey-js-ui.min.js',
            ['surveyjs-core'],
            ANMELDUNG_PLUGIN_VERSION,
            true
        );

        // Enqueue custom survey handler
        wp_enqueue_script(
            'anmeldung-survey-handler',
            ANMELDUNG_PLUGIN_URL . 'assets/js/survey-handler-wp.js',
            ['surveyjs-core', 'surveyjs-ui'],
            ANMELDUNG_PLUGIN_VERSION,
            true
        );

        // Enqueue custom CSS (optional)
        if (file_exists(ANMELDUNG_PLUGIN_DIR . 'assets/css/anmeldung.css')) {
            wp_enqueue_style(
                'anmeldung-forms',
                ANMELDUNG_PLUGIN_URL . 'assets/css/anmeldung.css',
                ['surveyjs-core'],
                ANMELDUNG_PLUGIN_VERSION
            );
        }
    }

    /**
     * Add custom fonts via inline CSS
     *
     * DSGVO-compliant: uses local WOFF2 files instead of Google Fonts
     */
    private function add_custom_fonts(): void
    {
        $fonts_url = $this->frontend_assets_url . 'fonts/opensans/';

        $custom_css = "
            @font-face {
                font-family: 'Open Sans';
                font-weight: 400;
                font-style: normal;
                font-display: swap;
                src: url('{$fonts_url}open-sans-v44-latin-regular.woff2') format('woff2');
            }

            @font-face {
                font-family: 'Open Sans';
                font-weight: 700;
                font-style: normal;
                font-display: swap;
                src: url('{$fonts_url}open-sans-v44-latin-700.woff2') format('woff2');
            }

            :root {
                --font-family: 'Open Sans', sans-serif !important;
            }

            .anmeldung-survey-container {
                font-family: var(--font-family);
                margin: 2rem 0;
            }

            .anmeldung-loading {
                text-align: center;
                padding: 2rem;
                color: #666;
            }

            .anmeldung-error {
                padding: 1rem;
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
                border-radius: 4px;
                margin: 1rem 0;
            }
        ";

        wp_add_inline_style('surveyjs-core', $custom_css);
    }
}
