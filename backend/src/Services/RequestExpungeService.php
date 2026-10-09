<?php
// src/Services/RequestExpungeService.php

declare(strict_types=1);

namespace App\Services;

use App\Config\Config;
use App\Config\TenantContext;

class RequestExpungeService
{
    /** One throttle marker per tenant: the cleanup runs for the active tenant only, never across tenants. */
    private const CACHE_FILE_TEMPLATE = __DIR__ . '/../../cache/last_expunge_%d.txt';
    private const CHECK_INTERVAL_HOURS = 6; // Only run every 6 hours

    public function __construct(
        private ExpungeService $expungeService
    ) {}

    /**
     * Check and run expunge if needed (throttled)
     * This is called on every page load but only executes periodically
     */
    public function checkAndRun(): ?array
    {
        $config = Config::getInstance();

        // No active tenant (login, platform admin before choosing a school): nothing to clean
        if (!TenantContext::isInitialized()) {
            return null;
        }

        // Check if expunge is enabled
        if ($config->autoExpungeDays <= 0) {
            return null;
        }

        // Check if we should run (throttling)
        if (!$this->shouldRun()) {
            return null;
        }

        try {
            // Run expunge
            $result = $this->expungeService->autoExpunge();

            // Rotate audit log (same schedule, no extra I/O on quiet systems)
            $retentionDays = $config->auditLogRetentionDays;
            $removedLines  = AuditLogger::rotate($retentionDays);
            if ($removedLines > 0) {
                error_log(sprintf(
                    'Audit log rotation: Removed %d entries older than %d days',
                    $removedLines,
                    $retentionDays
                ));
            }

            // Update last run timestamp
            $this->updateLastRun();

            // Log if something was deleted
            if ($result['deleted'] > 0) {
                error_log(sprintf(
                    'Request-based expunge: Deleted %d archived entries',
                    $result['deleted']
                ));
            }

            return $result;

        } catch (\Throwable $e) {
            // Don't break the page if expunge fails
            error_log('Request-based expunge failed: ' . $e->getMessage());
            return null;
        }
    }

    private function cacheFile(): string
    {
        return sprintf(self::CACHE_FILE_TEMPLATE, TenantContext::getTenantId());
    }

    /**
     * Check if expunge should run (throttling logic)
     */
    private function shouldRun(): bool
    {
        // Ensure cache directory exists
        $cacheDir = dirname($this->cacheFile());
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        // Check last run timestamp
        if (!file_exists($this->cacheFile())) {
            return true;
        }

        $lastRun = (int)file_get_contents($this->cacheFile());
        $hoursSinceLastRun = (time() - $lastRun) / 3600;

        return $hoursSinceLastRun >= self::CHECK_INTERVAL_HOURS;
    }

    /**
     * Update last run timestamp
     */
    private function updateLastRun(): void
    {
        $cacheDir = dirname($this->cacheFile());
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        file_put_contents($this->cacheFile(), (string)time());
    }

    /**
     * Get info about last run
     */
    public function getLastRunInfo(): array
    {
        if (!TenantContext::isInitialized() || !file_exists($this->cacheFile())) {
            return [
                'lastRun' => null,
                'nextRun' => null,
                'canRunNow' => true
            ];
        }

        $lastRun = (int)file_get_contents($this->cacheFile());
        $nextRun = $lastRun + (self::CHECK_INTERVAL_HOURS * 3600);

        return [
            'lastRun' => new \DateTimeImmutable('@' . $lastRun),
            'nextRun' => new \DateTimeImmutable('@' . $nextRun),
            'canRunNow' => time() >= $nextRun
        ];
    }

    /**
     * Force run expunge (bypass throttling)
     * Useful for manual trigger from admin panel
     */
    public function forceRun(): array
    {
        if (!TenantContext::isInitialized()) {
            return ['deleted' => 0, 'ids' => []];
        }

        $result = $this->expungeService->autoExpunge();
        $this->updateLastRun();
        
        return $result;
    }
}