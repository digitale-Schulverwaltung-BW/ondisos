<?php
/**
 * PSR-4 Autoloader Bridge
 *
 * Supports two namespaces:
 * - Ondisos\* → wordpress-plugin/includes/class-*.php
 * - Frontend\* → frontend/src/**\/*.php (existing code, unchanged)
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
 * Autoloader class
 */
class Autoloader
{
    /**
     * Register autoloader
     */
    public static function register(): void
    {
        spl_autoload_register([__CLASS__, 'autoload']);
    }

    /**
     * Autoload classes
     *
     * @param string $class Fully qualified class name
     */
    public static function autoload(string $class): void
    {
        // Handle Ondisos\* namespace
        if (strpos($class, 'Ondisos\\') === 0) {
            self::load_plugin_class($class);
            return;
        }

        // Handle Frontend\* namespace
        if (strpos($class, 'Frontend\\') === 0) {
            self::load_frontend_class($class);
            return;
        }
    }

    /**
     * Load plugin class (Ondisos\*)
     *
     * Converts: Ondisos\Shortcode → includes/class-shortcode.php
     *
     * @param string $class Fully qualified class name
     */
    private static function load_plugin_class(string $class): void
    {
        // Remove namespace prefix
        $class_name = str_replace('Ondisos\\', '', $class);

        // Convert to filename: Shortcode → class-shortcode.php
        $filename = 'class-' . strtolower(str_replace('_', '-', $class_name)) . '.php';

        // Build path
        $file = ONDISOS_PLUGIN_DIR . 'includes/' . $filename;

        if (file_exists($file)) {
            require_once $file;
        }
    }

    /**
     * Load frontend class (Frontend\*)
     *
     * Follows PSR-4:
     * Frontend\Services\AnmeldungService → frontend/src/Services/AnmeldungService.php
     *
     * @param string $class Fully qualified class name
     */
    private static function load_frontend_class(string $class): void
    {
        // Remove namespace prefix
        $relative_class = str_replace('Frontend\\', '', $class);

        // Convert to path: Services\AnmeldungService → Services/AnmeldungService.php
        $file = ONDISOS_FRONTEND_DIR . 'src/' . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    }
}

// Register autoloader
Autoloader::register();
