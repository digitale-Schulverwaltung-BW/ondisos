<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Entscheidet, ob ein Tenant gelöscht werden darf.
 *
 * Die Fremdschlüssel der Tenant-Tabellen sind ON DELETE CASCADE: Ein Löschen würde Anmeldungen
 * (auch im Papierkorb) stillschweigend mitnehmen. Deshalb sperrt die Policy, solange der Tenant
 * aktiv ist, Formulare oder Anmeldungen hat, und schützt Tenant 1 (default) immer.
 */
final class TenantDeletionPolicy
{
    public const DEFAULT_TENANT_ID = 1;

    /**
     * @return string|null Grund der Sperre (deutsch, für die Oberfläche) oder null, wenn das Löschen zulässig ist
     */
    public static function blocker(int $tenantId, bool $active, int $formCount, int $submissionCount): ?string
    {
        if ($tenantId === self::DEFAULT_TENANT_ID) {
            return 'Der Standard-Tenant (default) kann nicht gelöscht werden.';
        }
        if ($active) {
            return 'Der Tenant ist noch aktiv. Bitte zuerst deaktivieren.';
        }
        if ($formCount > 0) {
            return sprintf('Der Tenant hat noch %d Formular(e). Bitte zuerst alle Formulare löschen.', $formCount);
        }
        if ($submissionCount > 0) {
            return sprintf(
                'Der Tenant hat noch %d Anmeldung(en), auch im Papierkorb gezählt. Bitte zuerst entfernen: Sie würden sonst mitgelöscht.',
                $submissionCount
            );
        }
        return null;
    }
}
