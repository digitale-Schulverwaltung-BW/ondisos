<?php
/**
 * Plugin Name: Anmeldung Forms
 * Plugin URI: https://github.com/yourusername/anmeldung-forms
 * Description: SurveyJS-based Schulanmeldungs-System with integrated form builder and backend submission
 * Version: 2.0.0
 * Requires at least: 5.8
 * Requires PHP: 8.1
 * Author: Joerg Seyfried
 * Author URI: https://hhs.karlsruhe.de
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: anmeldung-forms
 * Domain Path: /languages
 */

declare(strict_types=1);

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Plugin version
define('ANMELDUNG_PLUGIN_VERSION', '2.0.0');

// Plugin paths
define('ANMELDUNG_PLUGIN_FILE', __FILE__);
define('ANMELDUNG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ANMELDUNG_PLUGIN_URL', plugin_dir_url(__FILE__));

// Frontend directory (one level up from wordpress-plugin/)
define('ANMELDUNG_FRONTEND_DIR', dirname(ANMELDUNG_PLUGIN_DIR) . '/frontend/');

// Minimum PHP version
define('ANMELDUNG_MIN_PHP_VERSION', '8.1');

/**
 * Check PHP version before loading plugin
 */
function anmeldung_forms_check_php_version(): void
{
    if (version_compare(PHP_VERSION, ANMELDUNG_MIN_PHP_VERSION, '<')) {
        add_action('admin_notices', function() {
            $message = sprintf(
                'Anmeldung Forms requires PHP %s or higher. You are running PHP %s.',
                ANMELDUNG_MIN_PHP_VERSION,
                PHP_VERSION
            );
            printf('<div class="error"><p>%s</p></div>', esc_html($message));
        });
        return;
    }

    anmeldung_forms_load();
}

/**
 * Load plugin classes
 */
function anmeldung_forms_load(): void
{
    // Load autoloader
    require_once ANMELDUNG_PLUGIN_DIR . 'includes/class-autoloader.php';

    // Load main plugin class
    require_once ANMELDUNG_PLUGIN_DIR . 'includes/class-plugin.php';

    // Initialize plugin
    Anmeldung_Forms\Plugin::get_instance();
}

// Check PHP version and load plugin
add_action('plugins_loaded', 'anmeldung_forms_check_php_version');

/**
 * Activation hook
 */
register_activation_hook(__FILE__, function() {
    // Check PHP version on activation
    if (version_compare(PHP_VERSION, ANMELDUNG_MIN_PHP_VERSION, '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            sprintf(
                'Anmeldung Forms requires PHP %s or higher. You are running PHP %s. Plugin has been deactivated.',
                ANMELDUNG_MIN_PHP_VERSION,
                PHP_VERSION
            ),
            'Plugin Activation Error',
            ['back_link' => true]
        );
    }

    // Set default options
    add_option('anmeldung_backend_url', '');
    add_option('anmeldung_from_email', '');

    // Flush rewrite rules
    flush_rewrite_rules();
});

/**
 * Deactivation hook
 */
register_deactivation_hook(__FILE__, function() {
    // Flush rewrite rules
    flush_rewrite_rules();
});
