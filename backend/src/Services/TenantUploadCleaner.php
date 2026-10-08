<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Entfernt das Upload-Verzeichnis eines gelöschten Tenants (uploads/tenant-<id>/, inkl. Branding und PDF-Anhänge).
 */
final class TenantUploadCleaner
{
    public function __construct(private readonly string $uploadsBase = __DIR__ . '/../../uploads')
    {
    }

    /** @return bool true, wenn das Verzeichnis nicht (mehr) existiert */
    public function remove(int $tenantId): bool
    {
        if ($tenantId <= 0) {
            return false;
        }
        $dir = $this->uploadsBase . '/tenant-' . $tenantId;
        if (!is_dir($dir)) {
            return true;
        }
        if (is_link($dir)) {
            return @unlink($dir);
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $path = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        return @rmdir($dir);
    }
}
