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
     * Why a form could not be loaded in this request.
     *
     * @var array<string,array{reason: string, detail: string, http_code: int, backend_url: string}>
     */
    private static array $failures = [];

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
        $result = $client->fetchFormConfigResult($formKey, $tenantSlug ?? self::tenantSlug());
        $config = $result['config'];

        if ($config === null) {
            self::$failures[$formKey] = [
                'reason'      => (string) ($result['reason'] ?? BackendApiClient::FAIL_ERROR),
                'detail'      => $result['detail'],
                'http_code'   => $result['http_code'],
                'backend_url' => self::redactUrl($client->baseUrl()),
            ];
            error_log(sprintf(
                'FormConfigLoader: form "%s" not loaded (%s): %s [backend %s]',
                $formKey,
                self::$failures[$formKey]['reason'],
                $result['detail'],
                self::$failures[$formKey]['backend_url']
            ));

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
     * Why ensure() returned false for $formKey (null if it did not fail in this request).
     *
     * reason is one of BackendApiClient::FAIL_UNREACHABLE / FAIL_UNAUTHORIZED / FAIL_NOT_FOUND / FAIL_ERROR.
     * detail and backend_url are meant for logs and administrators, not for the public.
     *
     * @return array{reason: string, detail: string, http_code: int, backend_url: string}|null
     */
    public static function failure(string $formKey): ?array
    {
        return self::$failures[$formKey] ?? null;
    }

    /**
     * Strip credentials ("user:password@") from a URL before it is logged or shown to an administrator.
     */
    public static function redactUrl(string $url): string
    {
        return (string) preg_replace('#(://)[^/@\s]*@#', '$1***@', $url);
    }

    /**
     * Forget request-local state. Intended for tests.
     */
    public static function reset(): void
    {
        self::$requested = [];
        self::$failures  = [];
    }
}
