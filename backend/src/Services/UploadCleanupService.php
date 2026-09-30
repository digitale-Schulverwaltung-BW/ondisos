<?php
// src/Services/UploadCleanupService.php

declare(strict_types=1);

namespace App\Services;

use App\Config\TenantContext;

/**
 * Removes the uploaded files of a permanently deleted Anmeldung.
 *
 * Files live in uploads/tenant-<tenantId>/{anmeldungId}_{name}.{ext}. Only regular
 * files directly inside the active tenant's directory whose name starts with
 * exactly "{anmeldungId}_" are deleted (id 5 never matches 50_*). Failures are
 * logged and never thrown, so a stuck file cannot abort the deletion itself.
 */
class UploadCleanupService
{
    private readonly string $uploadsBase;

    public function __construct(?string $uploadsBase = null)
    {
        $this->uploadsBase = $uploadsBase ?? __DIR__ . '/../../uploads';
    }

    /**
     * Delete all upload files of an Anmeldung in the current tenant.
     *
     * @return int Number of files deleted
     */
    public function deleteForAnmeldung(int $anmeldungId): int
    {
        if ($anmeldungId <= 0) {
            return 0;
        }

        try {
            $tenantId = TenantContext::getTenantId();
        } catch (\RuntimeException $e) {
            // Uninitialized or all-tenants mode: no single tenant directory applies
            $this->logFailure($anmeldungId, '', 'no single tenant context: ' . $e->getMessage());
            return 0;
        }

        $base = realpath($this->uploadsBase);
        if ($base === false) {
            return 0;
        }

        $tenantDir = realpath($base . '/tenant-' . $tenantId);
        if ($tenantDir === false || !is_dir($tenantDir) || dirname($tenantDir) !== $base) {
            return 0; // nothing uploaded for this tenant
        }

        $prefix = $anmeldungId . '_';
        $names = @scandir($tenantDir);
        if ($names === false) {
            $this->logFailure($anmeldungId, '', 'cannot read upload directory');
            return 0;
        }

        $deleted = 0;
        foreach ($names as $name) {
            if (!str_starts_with($name, $prefix)) {
                continue; // also skips "." and ".."
            }

            $path = $tenantDir . '/' . $name;
            // Symlinks are removed as links only; anything else must resolve inside the tenant dir
            if (is_link($path)) {
                $ok = @unlink($path);
            } else {
                $real = realpath($path);
                if ($real === false || !is_file($real) || dirname($real) !== $tenantDir) {
                    continue;
                }
                $ok = @unlink($real);
            }

            if ($ok) {
                $deleted++;
            } else {
                $this->logFailure($anmeldungId, $name, 'unlink failed');
            }
        }

        if ($deleted > 0) {
            AuditLogger::uploadsDeleted($anmeldungId, $deleted);
        }

        return $deleted;
    }

    private function logFailure(int $anmeldungId, string $file, string $reason): void
    {
        error_log(sprintf(
            'Upload cleanup failed for Anmeldung #%d%s: %s',
            $anmeldungId,
            $file !== '' ? " ($file)" : '',
            $reason
        ));
        try {
            AuditLogger::uploadCleanupFailed($anmeldungId, $file, $reason);
        } catch (\Throwable $e) {
            // auditing must never break the deletion
        }
    }
}
