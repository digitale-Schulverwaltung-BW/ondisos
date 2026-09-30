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

        register_setting(
            self::OPTION_GROUP,
            'ondisos_tenant_slug',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_title',
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

        // Tenant slug field
        add_settings_field(
            'ondisos_tenant_slug',
            'Tenant-Slug',
            [$this, 'render_tenant_slug_field'],
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
     * Render Tenant slug field
     */
    public function render_tenant_slug_field(): void
    {
        $value = get_option('ondisos_tenant_slug', '');
        $env_value = getenv('TENANT_SLUG') ?: 'Nicht gesetzt';

        ?>
        <input type="text"
               name="ondisos_tenant_slug"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text"
               placeholder="default">
        <p class="description">
            Kennung der Schule (Tenant), deren Formular-Konfiguration vom Backend geladen wird. Leer = <code>default</code>.<br>
            <strong>Aktueller Wert aus .env:</strong> <code><?php echo esc_html($env_value); ?></code><br>
            <strong>Verwendet:</strong> <code><?php echo esc_html(Form_Config_Loader::tenant_slug()); ?></code>
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
        // Form configuration is managed per tenant in the backend; there is no
        // list endpoint, so forms are referenced by key: [ondisos form="<key>"].
        echo '<p>Die Formulare werden pro Tenant im Backend verwaltet. '
            . 'Binden Sie ein Formular mit dem Shortcode <code>[ondisos form="FORMULARKEY"]</code> in eine Seite ein '
            . '(aktueller Tenant: <code>' . esc_html(Form_Config_Loader::tenant_slug()) . '</code>).</p>';
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
