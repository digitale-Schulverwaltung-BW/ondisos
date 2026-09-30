<?php
/**
 * Form Config Loader
 *
 * Form configuration lives in the backend (per tenant). This class fetches it
 * via BackendApiClient and injects it into FormConfig, mirroring what
 * frontend/public/index.php does for the standalone frontend.
 *
 * @package Ondisos
 */

declare(strict_types=1);

namespace Ondisos;

use Frontend\Config\FormConfig;
use Frontend\Services\BackendApiClient;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Form_Config_Loader
{
    /**
     * Form keys already requested in this PHP request (true = loaded, false = failed).
     *
     * @var array<string,bool>
     */
    private static array $requested = [];

    /**
     * Tenant slug used to look up form config in the backend.
     *
     * Priority: WordPress option > TENANT_SLUG env > 'default'.
     */
    public static function tenant_slug(): string
    {
        $slug = (string) get_option('ondisos_tenant_slug', '');
        if ($slug === '') {
            $slug = (string) (getenv('TENANT_SLUG') ?: '');
        }

        return $slug !== '' ? $slug : 'default';
    }

    /**
     * Make sure the config for $form_key is loaded into FormConfig.
     *
     * @return bool True if the form exists for the tenant and is loaded,
     *              false if unknown or the backend is unreachable.
     */
    public static function ensure(string $form_key): bool
    {
        if ($form_key === '') {
            return false;
        }

        if (FormConfig::exists($form_key)) {
            return true;
        }

        if (isset(self::$requested[$form_key])) {
            return self::$requested[$form_key];
        }

        $config = (new BackendApiClient())->fetchFormConfig($form_key, self::tenant_slug());

        if ($config === null) {
            return self::$requested[$form_key] = false;
        }

        // FormConfig::load() replaces the whole config; keep forms loaded earlier
        // in this request (several shortcodes on one page).
        $all = [];
        foreach (FormConfig::getAllFormKeys() as $key) {
            $all[$key] = FormConfig::get($key);
        }
        $all[$form_key] = $config;
        FormConfig::load($all);

        return self::$requested[$form_key] = true;
    }
}
