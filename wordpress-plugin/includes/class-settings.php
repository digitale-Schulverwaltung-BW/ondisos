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
use Frontend\Config\FormConfigLoader;
use Frontend\Services\BackendApiClient;

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
                'sanitize_callback' => [$this, 'sanitize_backend_url'],
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

        register_setting(
            self::OPTION_GROUP,
            'ondisos_tenant_api_secret',
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_api_secret'],
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

        // Tenant API secret field
        add_settings_field(
            'ondisos_tenant_api_secret',
            'Tenant-API-Secret',
            [$this, 'render_api_secret_field'],
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
     * Keep the stored secret when the field is submitted empty.
     *
     * The secret is never rendered back into the form, so an empty submit
     * means "unchanged", not "delete".
     */
    public function sanitize_api_secret($value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : (string) get_option('ondisos_tenant_api_secret', '');
    }

    /**
     * Render Tenant API secret field (write-only)
     */
    public function render_api_secret_field(): void
    {
        $is_set = (string) get_option('ondisos_tenant_api_secret', '') !== ''
            || (string) (getenv('TENANT_API_SECRET') ?: '') !== '';

        ?>
        <input type="password"
               name="ondisos_tenant_api_secret"
               value=""
               class="regular-text"
               autocomplete="new-password"
               placeholder="<?php echo $is_set ? '(gesetzt — leer lassen, um beizubehalten)' : ''; ?>">
        <p class="description">
            API-Secret des Tenants zum Signieren der Backend-Anfragen (aus der Tenant-Verwaltung im Backend).
            Wird nie im Browser ausgegeben. Alternativ <code>TENANT_API_SECRET</code> in der .env.<br>
            <strong>Status:</strong> <?php echo $is_set ? 'gesetzt' : '<em>nicht gesetzt — Formulare können nicht abgesendet werden</em>'; ?>
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

            <?php $this->render_connection_status(); ?>

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
    /**
     * Sanitize the Backend API URL and check right away whether the backend answers there.
     *
     * The URL is saved either way (the backend may be started later); an unreachable backend or a
     * "localhost" inside a Docker container is reported as a warning so typos do not stay hidden.
     */
    public function sanitize_backend_url($value): string
    {
        $url = rtrim(esc_url_raw(trim((string) $value)), '/');

        if ($url === '') {
            return '';
        }

        $shown = FormConfigLoader::redactUrl($url);
        $host  = strtolower((string) wp_parse_url($url, PHP_URL_HOST));

        if (self::in_docker() && in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            add_settings_error(
                'ondisos_backend_url',
                'ondisos_backend_localhost',
                'Hinweis: In einem Docker-Container ist „' . $host . '" der Container selbst, nicht Ihr Rechner. '
                . 'Verwenden Sie z. B. host.docker.internal (Docker Desktop) oder den Namen des Backend-Dienstes im gemeinsamen Docker-Netz.',
                'warning'
            );
        }

        $health = (new BackendApiClient($url))->healthCheck();
        if ($health['status'] !== 'ok') {
            add_settings_error(
                'ondisos_backend_url',
                'ondisos_backend_unreachable',
                sprintf(
                    'Das Backend antwortet unter %s nicht (%s). Die URL wurde trotzdem gespeichert — bitte prüfen (Tippfehler, Port, Netzwerk).',
                    $shown,
                    $health['reason'] ?: 'keine Antwort'
                ),
                'warning'
            );
        }

        return $url;
    }

    /**
     * Connection status shown at the top of the settings page: backend, tenant, secret.
     */
    private function render_connection_status(): void
    {
        $client = new BackendApiClient();
        $url    = FormConfigLoader::redactUrl($client->baseUrl());
        $slug   = Form_Config_Loader::tenant_slug();
        $health = $client->healthCheck();
        $reachable = $health['status'] === 'ok';

        $rows = [];

        $rows[] = $reachable
            ? ['ok', sprintf('Backend erreichbar: %s', $url)]
            : ['error', sprintf(
                'Backend NICHT erreichbar: %s — %s. Prüfen Sie „Backend API URL" (Tippfehler, Port, Netzwerk).',
                $url,
                $health['reason'] ?: 'keine Antwort'
            )];

        if (!$reachable && self::in_docker() && preg_match('#//(localhost|127\.0\.0\.1)([:/]|$)#i', $url)) {
            $rows[] = ['error', 'In einem Docker-Container ist „localhost" der Container selbst. Nutzen Sie host.docker.internal oder den Dienstnamen im gemeinsamen Docker-Netz.'];
        }

        if ($reachable) {
            $tenant = $client->checkTenant($slug);
            $rows[] = $tenant['ok']
                ? ['ok', sprintf('Tenant „%s": vom Backend akzeptiert', $slug)]
                : ['error', sprintf('Tenant „%s": %s', $slug, $tenant['reason'] === 'unauthorized'
                    ? 'unbekannt oder inaktiv — „Tenant-Slug" prüfen.'
                    : 'nicht prüfbar (' . $tenant['detail'] . ')')];
        } else {
            $rows[] = ['info', sprintf('Tenant „%s": nicht geprüft (Backend nicht erreichbar)', $slug)];
        }

        $has_secret = (string) (getenv('TENANT_API_SECRET') ?: '') !== '';
        if (!$has_secret) {
            $rows[] = ['error', 'Tenant-API-Secret: NICHT gesetzt — Formulare lassen sich nicht absenden.'];
        } elseif (!$reachable) {
            $rows[] = ['info', 'Tenant-API-Secret: gesetzt (nicht geprüft, Backend nicht erreichbar)'];
        } else {
            // Signed request: also proves that the secret belongs to this tenant, and lists the tenant's forms.
            $forms = $client->fetchTenantForms($slug);
            if ($forms['ok']) {
                $rows[] = ['ok', 'Tenant-API-Secret: passt zum Tenant'];
                $rows[] = $forms['forms'] === []
                    ? ['warning', sprintf('Tenant „%s" hat 0 Formulare: Besucher sehen „Formular nicht gefunden". Im Backend unter „Formulare" anlegen oder von einem anderen Tenant übernehmen.', $slug)]
                    : ['ok', sprintf('%d Formular(e): %s', count($forms['forms']), implode(', ', array_slice($forms['forms'], 0, 12)) . (count($forms['forms']) > 12 ? ' …' : ''))];
            } elseif ($forms['reason'] === 'unauthorized') {
                $rows[] = ['error', 'Tenant-API-Secret: wird vom Backend abgelehnt — es passt nicht zum Tenant „' . $slug . '" (oder der Tenant ist inaktiv). Secret im Backend unter „Tenants" prüfen.'];
            } elseif ($forms['reason'] === 'not_found') {
                $rows[] = ['info', 'Tenant-API-Secret: gesetzt (das Backend ist älter als 3.1 und kann es nicht prüfen)'];
            } else {
                $rows[] = ['info', 'Tenant-API-Secret: gesetzt (nicht prüfbar: ' . $forms['detail'] . ')'];
            }
        }

        echo '<h2>Verbindungsstatus</h2>';
        foreach ($rows as [$type, $text]) {
            printf('<div class="notice notice-%s inline"><p>%s</p></div>', esc_attr($type === 'ok' ? 'success' : $type), esc_html($text));
        }
        echo '<p class="description">Der Status wird beim Öffnen dieser Seite geprüft. Nach dem Speichern erneut laden.</p>';
    }

    /**
     * True inside a Docker container (where "localhost" is the container, not the host).
     */
    private static function in_docker(): bool
    {
        return file_exists('/.dockerenv');
    }

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
