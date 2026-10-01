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
     * Survey and theme text per form key, for forms loaded with ensureWithSurvey().
     * null = the backend has none (use the file in frontend/surveys/).
     *
     * @var array<string, array{survey: ?string, theme: ?string}>
     */
    private static array $bundles = [];

    /** @var array<string,bool> form keys for which ensureWithSurvey() already failed in this request */
    private static array $bundleFailed = [];

    /** How long a cached copy may be served while the backend is unreachable (7 days). */
    public const MAX_STALE_SECONDS = 604800;

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

        self::merge($formKey, $config);

        return self::$requested[$formKey] = true;
    }

    /**
     * Load config, survey and theme of a form (used by the pages that render it).
     *
     * Asks the backend with the ETag of the cached copy; "not modified" and — if the backend cannot be
     * reached — a cached copy younger than MAX_STALE_SECONDS are served from the cache. Without a usable
     * cache a backend failure means false, like in ensure().
     *
     * @return bool True if the form is loaded; false if unknown, not allowed, or no data is available.
     */
    public static function ensureWithSurvey(
        string $formKey,
        ?BackendApiClient $client = null,
        ?string $tenantSlug = null,
        ?FormBundleCache $cache = null
    ): bool {
        if ($formKey === '') {
            return false;
        }
        if (isset(self::$bundles[$formKey])) {
            return true;
        }
        if (isset(self::$bundleFailed[$formKey])) {
            return false;
        }

        $slug   = $tenantSlug ?? self::tenantSlug();
        $client ??= new BackendApiClient();
        $cache  ??= FormBundleCache::default();

        $cached = $cache->get($slug, $formKey);
        $answer = $client->fetchFormBundle($formKey, $slug, $cached['etag'] ?? null);

        $use = null; // [config, survey, theme]
        switch ($answer['status']) {
            case 'ok':
                $use = [$answer['config'], $answer['survey_json'] ?? null, $answer['theme_json'] ?? null];
                $cache->put($slug, $formKey, $answer['etag'] ?? null, $answer['config'], $use[1], $use[2]);
                break;

            case 'not_modified':
                if ($cached !== null) {
                    $use = [$cached['config'], $cached['survey_json'], $cached['theme_json']];
                }
                break;

            case 'not_found':
            case 'denied':
                $cache->forget($slug, $formKey); // gone or no longer allowed: do not keep serving it
                break;

            default: // 'error': backend unreachable or broken
                if ($cached !== null && (time() - $cached['stored_at']) <= self::MAX_STALE_SECONDS) {
                    error_log("Backend unavailable, serving cached form '{$formKey}'");
                    $use = [$cached['config'], $cached['survey_json'], $cached['theme_json']];
                }
        }

        if ($use === null) {
            if (isset($answer['reason'])) {
                self::$failures[$formKey] = [
                    'reason'      => (string) $answer['reason'],
                    'detail'      => (string) ($answer['detail'] ?? ''),
                    'http_code'   => (int) ($answer['http_code'] ?? 0),
                    'backend_url' => self::redactUrl($client->baseUrl()),
                ];
                error_log(sprintf(
                    'FormConfigLoader: form "%s" not loaded (%s): %s [backend %s]',
                    $formKey,
                    self::$failures[$formKey]['reason'],
                    self::$failures[$formKey]['detail'],
                    self::$failures[$formKey]['backend_url']
                ));
            }
            return self::$bundleFailed[$formKey] = false;
        }

        self::merge($formKey, $use[0]);
        self::$bundles[$formKey] = ['survey' => $use[1], 'theme' => $use[2]];

        return true;
    }

    /** Survey JSON text delivered by the backend for a form loaded with ensureWithSurvey(); null = use the file. */
    public static function surveyJson(string $formKey): ?string
    {
        return self::$bundles[$formKey]['survey'] ?? null;
    }

    /** Theme JSON text delivered by the backend; null = use the file. */
    public static function themeJson(string $formKey): ?string
    {
        return self::$bundles[$formKey]['theme'] ?? null;
    }

    /** @param array<string,mixed> $config */
    private static function merge(string $formKey, array $config): void
    {
        $all = [];
        foreach (FormConfig::getAllFormKeys() as $key) {
            $all[$key] = FormConfig::get($key);
        }
        $all[$formKey] = $config;
        FormConfig::load($all);
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
        self::$bundles = [];
        self::$bundleFailed = [];
        self::$failures = [];
    }
}
