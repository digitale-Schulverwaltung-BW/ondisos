<?php
/**
 * Plugin Name: ondisos - Onboarding Digital Souverän + Open Source
 * Plugin URI: https://gitlab.hhs.karlsruhe.de/digitale-schulverwaltung/ondisos
 * Description: SurveyJS-based Schulanmeldungs-System with secure backend submission
 * Version: 2.0.0
 * Requires at least: 5.8
 * Requires PHP: 8.1
 * Author: Joerg Seyfried
 * Author URI: https://hhs.karlsruhe.de
 * License: MIT
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ondisos
 * Domain Path: /languages
 */

declare(strict_types=1);

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Plugin version
define('ONDISOS_PLUGIN_VERSION', '2.0.0');

// Plugin paths
define('ONDISOS_PLUGIN_FILE', __FILE__);
define('ONDISOS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ONDISOS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Frontend directory: sibling of the plugin in the plugins directory
// Both Docker (volume mount) and production (symlink) use this layout:
//   plugins/ondisos/           ← this plugin
//   plugins/ondisos-frontend/  ← frontend
define('ONDISOS_FRONTEND_DIR', dirname(ONDISOS_PLUGIN_DIR) . '/ondisos-frontend/');

// Minimum PHP version
define('ONDISOS_MIN_PHP_VERSION', '8.1');

/**
 * Check PHP version before loading plugin
 */
function ondisos_check_php_version(): void
{
    if (version_compare(PHP_VERSION, ONDISOS_MIN_PHP_VERSION, '<')) {
        add_action('admin_notices', function() {
            $message = sprintf(
                'ondisos requires PHP %s or higher. You are running PHP %s.',
                ONDISOS_MIN_PHP_VERSION,
                PHP_VERSION
            );
            printf('<div class="error"><p>%s</p></div>', esc_html($message));
        });
        return;
    }

    ondisos_load();
}

/**
 * Load plugin classes
 */
function ondisos_load(): void
{
    // Load autoloader
    require_once ONDISOS_PLUGIN_DIR . 'includes/class-autoloader.php';

    // Load main plugin class
    require_once ONDISOS_PLUGIN_DIR . 'includes/class-plugin.php';

    // Initialize plugin
    Ondisos\Plugin::get_instance();
}

// Check PHP version and load plugin
add_action('plugins_loaded', 'ondisos_check_php_version');

/**
 * Activation hook
 */
register_activation_hook(__FILE__, function() {
    // Check PHP version on activation
    if (version_compare(PHP_VERSION, ONDISOS_MIN_PHP_VERSION, '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            sprintf(
                'ondisos requires PHP %s or higher. You are running PHP %s. Plugin has been deactivated.',
                ONDISOS_MIN_PHP_VERSION,
                PHP_VERSION
            ),
            'Plugin Activation Error',
            ['back_link' => true]
        );
    }

    // Set default options
    add_option('ondisos_backend_url', '');
    add_option('ondisos_from_email', '');

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
