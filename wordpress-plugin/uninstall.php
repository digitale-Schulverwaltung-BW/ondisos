<?php
/**
 * Uninstall Script
 *
 * Cleanup when plugin is deleted (not just deactivated)
 *
 * @package Ondisos
 */

declare(strict_types=1);

// Exit if not called by WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Delete plugin options
 */
delete_option('ondisos_backend_url');
delete_option('ondisos_from_email');

/**
 * Clean up transients (if any)
 */
delete_transient('ondisos_cache');

/**
 * Note: We do NOT delete any submission data as it's stored
 * in the external backend system, not in WordPress database.
 */
