<?php
/**
 * Settings Page
 *
 * Admin settings page for plugin configuration.
 * Location: Settings → Ondisos
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
 * Settings handler
 */
class Settings
{
    /**
     * Settings page slug
     */
    private const PAGE_SLUG = 'ondisos-settings';

    /**
     * Option group
     */
    private const OPTION_GROUP = 'ondisos';

    /**
     * Constructor - register hooks
     */
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Add settings page to admin menu
     */
    public function add_settings_page(): void
    {
        add_options_page(
            'Ondisos Settings',           // Page title
            'Ondisos',                     // Menu title
            'manage_options',                      // Capability
            self::PAGE_SLUG,                       // Menu slug
            [$this, 'render_settings_page']       // Callback
        );
    }

    /**
     * Register settings
     */
    public function register_settings(): void
    {
        // Register settings
        register_setting(
            self::OPTION_GROUP,
            'ondisos_backend_url',
            [
                'type' => 'string',
                'sanitize_callback' => 'esc_url_raw',
                'default' => ''
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'ondisos_from_email',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_email',
                'default' => ''
            ]
        );

        // Add settings section
        add_settings_section(
            'ondisos_main_section',
            'Haupteinstellungen',
            [$this, 'render_section_description'],
            self::PAGE_SLUG
        );

        // Backend URL field
        add_settings_field(
            'ondisos_backend_url',
            'Backend API URL',
            [$this, 'render_backend_url_field'],
            self::PAGE_SLUG,
            'ondisos_main_section'
        );

        // From Email field
        add_settings_field(
            'ondisos_from_email',
            'Von E-Mail-Adresse',
            [$this, 'render_from_email_field'],
            self::PAGE_SLUG,
            'ondisos_main_section'
        );

        // Forms list section
        add_settings_section(
            'ondisos_forms_section',
            'Verfügbare Formulare',
            [$this, 'render_forms_section_description'],
            self::PAGE_SLUG
        );
    }

    /**
     * Render section description
     */
    public function render_section_description(): void
    {
        echo '<p>Diese Einstellungen überschreiben die Werte aus der .env-Datei.</p>';
    }

    /**
     * Render forms section description
     */
    public function render_forms_section_description(): void
    {
        echo '<p>Kopieren Sie den Shortcode und fügen Sie ihn in eine Seite oder einen Beitrag ein.</p>';
    }

    /**
     * Render Backend URL field
     */
    public function render_backend_url_field(): void
    {
        $value = get_option('ondisos_backend_url', '');
        $env_value = getenv('BACKEND_API_URL') ?: 'Nicht gesetzt';

        ?>
        <input type="url"
               name="ondisos_backend_url"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text"
               placeholder="http://intranet.example.com/backend/api">
        <p class="description">
            Backend API URL für Formular-Submissions.<br>
            <strong>Aktueller Wert aus .env:</strong> <code><?php echo esc_html($env_value); ?></code>
        </p>
        <?php
    }

    /**
     * Render From Email field
     */
    public function render_from_email_field(): void
    {
        $value = get_option('ondisos_from_email', '');
        $env_value = getenv('FROM_EMAIL') ?: 'Nicht gesetzt';

        ?>
        <input type="email"
               name="ondisos_from_email"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text"
               placeholder="noreply@example.com">
        <p class="description">
            Absender-E-Mail-Adresse für Benachrichtigungen.<br>
            <strong>Aktueller Wert aus .env:</strong> <code><?php echo esc_html($env_value); ?></code>
        </p>
        <?php
    }

    /**
     * Render settings page
     */
    public function render_settings_page(): void
    {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::PAGE_SLUG);
                ?>

                <h2>Verfügbare Formulare</h2>
                <?php $this->render_forms_list(); ?>

                <?php submit_button('Einstellungen speichern'); ?>
            </form>

            <hr>

            <h2>Systeminfo</h2>
            <?php $this->render_system_info(); ?>
        </div>
        <?php
    }

    /**
     * Render list of available forms
     */
    private function render_forms_list(): void
    {
        try {
            $form_keys = FormConfig::getAllFormKeys();

            if (empty($form_keys)) {
                echo '<p>Keine Formulare konfiguriert.</p>';
                return;
            }

            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Form Key</th><th>Shortcode</th><th>Benachrichtigung</th></tr></thead>';
            echo '<tbody>';

            foreach ($form_keys as $form_key) {
                $config = FormConfig::get($form_key);
                $shortcode = sprintf('[ondisos form="%s"]', esc_attr($form_key));
                $notify_email = $config['notify_email'] ?? 'Nicht gesetzt';

                echo '<tr>';
                echo '<td><code>' . esc_html($form_key) . '</code></td>';
                echo '<td><input type="text" value="' . esc_attr($shortcode) . '" readonly onclick="this.select()" style="width: 100%;"></td>';
                echo '<td>' . esc_html($notify_email) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';

        } catch (\Exception $e) {
            echo '<div class="notice notice-error"><p>Fehler beim Laden der Formulare: ' . esc_html($e->getMessage()) . '</p></div>';
        }
    }

    /**
     * Render system information
     */
    private function render_system_info(): void
    {
        ?>
        <table class="widefat">
            <tbody>
                <tr>
                    <th>Plugin Version</th>
                    <td><?php echo esc_html(ONDISOS_PLUGIN_VERSION); ?></td>
                </tr>
                <tr>
                    <th>PHP Version</th>
                    <td><?php echo esc_html(PHP_VERSION); ?></td>
                </tr>
                <tr>
                    <th>WordPress Version</th>
                    <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                </tr>
                <tr>
                    <th>Plugin Directory</th>
                    <td><code><?php echo esc_html(ONDISOS_PLUGIN_DIR); ?></code></td>
                </tr>
                <tr>
                    <th>Frontend Directory</th>
                    <td><code><?php echo esc_html(ONDISOS_FRONTEND_DIR); ?></code></td>
                </tr>
                <tr>
                    <th>Frontend Directory Exists</th>
                    <td><?php echo is_dir(ONDISOS_FRONTEND_DIR) ? '✅ Ja' : '❌ Nein'; ?></td>
                </tr>
                <tr>
                    <th>Backend API URL (effective)</th>
                    <td><code><?php echo esc_html(FormConfig::getBackendUrl()); ?></code></td>
                </tr>
            </tbody>
        </table>
        <?php
    }
}
