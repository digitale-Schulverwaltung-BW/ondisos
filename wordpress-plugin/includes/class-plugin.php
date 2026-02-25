<?php
/**
 * Main Plugin Class
 *
 * Orchestrates all plugin components and loads frontend environment.
 *
 * @package Ondisos
 */

declare(strict_types=1);

namespace Ondisos;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin orchestrator
 */
class Plugin
{
    /**
     * Singleton instance
     */
    private static ?Plugin $instance = null;

    /**
     * Shortcode handler
     */
    private Shortcode $shortcode;

    /**
     * AJAX handler
     */
    private Ajax_Handler $ajax_handler;

    /**
     * PDF proxy handler
     */
    private Pdf_Proxy $pdf_proxy;

    /**
     * Assets handler
     */
    private Assets $assets;

    /**
     * Settings handler
     */
    private Settings $settings;

    /**
     * Get singleton instance
     */
    public static function get_instance(): Plugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor (singleton)
     */
    private function __construct()
    {
        $this->load_frontend_config();
        $this->init_components();
    }

    /**
     * Load frontend environment configuration
     *
     * Priority:
     * 1. WordPress options (highest)
     * 2. .env file
     * 3. Hardcoded fallbacks
     */
    private function load_frontend_config(): void
    {
        // Load .env file if exists
        $env_file = ONDISOS_FRONTEND_DIR . '.env';
        if (file_exists($env_file)) {
            $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                // Skip comments
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }

                // Parse KEY=VALUE
                if (strpos($line, '=') !== false) {
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);

                    // Remove quotes
                    $value = trim($value, '"\'');

                    // Set environment variable
                    putenv("$key=$value");
                    $_ENV[$key] = $value;
                }
            }
        }

        // Override with WordPress options (highest priority)
        $backend_url = get_option('ondisos_backend_url');
        if (!empty($backend_url)) {
            putenv('BACKEND_API_URL=' . $backend_url);
            $_ENV['BACKEND_API_URL'] = $backend_url;
        }

        $from_email = get_option('ondisos_from_email');
        if (!empty($from_email)) {
            putenv('FROM_EMAIL=' . $from_email);
            $_ENV['FROM_EMAIL'] = $from_email;
        }
    }

    /**
     * Initialize plugin components
     */
    private function init_components(): void
    {
        // Initialize shortcode handler
        $this->shortcode = new Shortcode();

        // Initialize AJAX handler
        $this->ajax_handler = new Ajax_Handler();

        // Initialize PDF proxy handler
        $this->pdf_proxy = new Pdf_Proxy();

        // Initialize assets handler
        $this->assets = new Assets();

        // Initialize settings page (admin only)
        if (is_admin()) {
            $this->settings = new Settings();
        }
    }

    /**
     * Get shortcode handler
     */
    public function get_shortcode(): Shortcode
    {
        return $this->shortcode;
    }

    /**
     * Get AJAX handler
     */
    public function get_ajax_handler(): Ajax_Handler
    {
        return $this->ajax_handler;
    }

    /**
     * Get assets handler
     */
    public function get_assets(): Assets
    {
        return $this->assets;
    }

    /**
     * Get settings handler
     */
    public function get_settings(): ?Settings
    {
        return $this->settings ?? null;
    }
}
