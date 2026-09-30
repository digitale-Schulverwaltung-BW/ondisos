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
}
