<?php
/**
 * Form Config Loader
 *
 * Form configuration lives in the backend (per tenant). This is a thin WordPress
 * wrapper around Frontend\Config\FormConfigLoader that adds the Tenant-Slug setting.
 *
 * @package Ondisos
 */

declare(strict_types=1);

namespace Ondisos;

use Frontend\Config\FormBundleCache;
use Frontend\Config\FormConfigLoader;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Form_Config_Loader
{
    /**
     * Tenant slug used to look up form config in the backend.
     *
     * Priority: WordPress option > TENANT_SLUG env > 'default'.
     */
    public static function tenant_slug(): string
    {
        $slug = (string) get_option('ondisos_tenant_slug', '');

        return $slug !== '' ? $slug : FormConfigLoader::tenantSlug();
    }

    /**
     * Make sure the config for $form_key is loaded into FormConfig.
     *
     * @return bool True if the form exists for the tenant and is loaded,
     *              false if unknown or the backend is unreachable.
     */
    public static function ensure(string $form_key): bool
    {
        return FormConfigLoader::ensure($form_key, null, self::tenant_slug());
    }

    /**
     * Same as ensure(), but also loads the survey and theme the backend delivers (used to render a form).
     * Served from a cache while the backend says "not modified" or is unreachable.
     */
    public static function ensure_with_survey(string $form_key): bool
    {
        return FormConfigLoader::ensureWithSurvey($form_key, null, self::tenant_slug(), self::cache());
    }

    /**
     * Cache directory inside the uploads folder (always writable in WordPress). Entries start with a PHP
     * guard line, so they are not readable over HTTP. If the directory cannot be created, caching is off.
     */
    private static function cache(): FormBundleCache
    {
        $uploads = wp_upload_dir(null, false);
        $base    = is_array($uploads) && !empty($uploads['basedir']) ? (string) $uploads['basedir'] : sys_get_temp_dir();

        return new FormBundleCache(rtrim($base, '/') . '/ondisos-cache');
    }
}
