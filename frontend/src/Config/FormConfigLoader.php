<?php
// frontend/src/Config/FormConfigLoader.php

declare(strict_types=1);

namespace Frontend\Config;

use Frontend\Services\BackendApiClient;

/**
 * Makes sure the configuration of a form is loaded into FormConfig.
 *
 * Form configuration lives in the backend (per tenant). Every entry point that calls
 * FormConfig::exists()/get() — index.php, save.php, ical.php and the WordPress plugin —
 * must make sure the config has been fetched first; this is the single place that does it.
 */
class FormConfigLoader
{
    /**
     * Form keys already requested in this PHP request (true = loaded, false = failed),
     * so a failing backend is asked only once per request.
     *
     * @var array<string,bool>
     */
    private static array $requested = [];

    /**
     * Tenant slug of this frontend: TENANT_SLUG env, 'default' for single-tenant setups.
     */
    public static function tenantSlug(): string
    {
        return getenv('TENANT_SLUG') ?: 'default';
    }

    /**
     * Load the config for $formKey (if not loaded yet).
     *
     * Previously loaded forms are kept, so several forms can be used in one request.
     *
     * @return bool True if the form exists for the tenant and is loaded; false if it is
     *              unknown or the backend is unreachable.
     */
    public static function ensure(string $formKey, ?BackendApiClient $client = null, ?string $tenantSlug = null): bool
    {
        if ($formKey === '') {
            return false;
        }

        if (FormConfig::exists($formKey)) {
            return true;
        }

        if (isset(self::$requested[$formKey])) {
            return self::$requested[$formKey];
        }

        $client ??= new BackendApiClient();
        $config = $client->fetchFormConfig($formKey, $tenantSlug ?? self::tenantSlug());

        if ($config === null) {
            return self::$requested[$formKey] = false;
        }

        $all = [];
        foreach (FormConfig::getAllFormKeys() as $key) {
            $all[$key] = FormConfig::get($key);
        }
        $all[$formKey] = $config;
        FormConfig::load($all);

        return self::$requested[$formKey] = true;
    }

    /**
     * Forget request-local state. Intended for tests.
     */
    public static function reset(): void
    {
        self::$requested = [];
    }
}
