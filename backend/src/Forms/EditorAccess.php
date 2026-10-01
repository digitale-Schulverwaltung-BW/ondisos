<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * Who may use the form editor, and as which role (see FormConfigSchema::ROLE_*).
 *
 *  - multi-tenant: platform admin → platform role; tenant admin (session has a tenant) → tenant role; anyone else → none
 *  - single tenant: the person operating the installation → platform role (there are no tenant admins)
 *
 * Authentication itself (is someone logged in?) is auth.php's job; this only maps a session to a role.
 */
final class EditorAccess
{
    /**
     * @param array<string,mixed> $session $_SESSION
     * @return string|null FormConfigSchema::ROLE_PLATFORM, ROLE_TENANT, or null for "no access"
     */
    public static function roleFor(array $session, bool $multiTenant): ?string
    {
        if (!$multiTenant) {
            return FormConfigSchema::ROLE_PLATFORM;
        }
        if (!empty($session['is_platform_admin'])) {
            return FormConfigSchema::ROLE_PLATFORM;
        }
        if (!empty($session['admin_logged_in']) && !empty($session['tenant_id'])) {
            return FormConfigSchema::ROLE_TENANT;
        }
        return null;
    }
}
