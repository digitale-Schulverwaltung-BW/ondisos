<?php
// src/Services/RequestExpungeService.php

declare(strict_types=1);

namespace App\Services;

use App\Config\Config;

class RequestExpungeService
{
    private const CACHE_FILE = __DIR__ . '/../../cache/last_expunge.txt';
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

    /**
     * Check if expunge should run (throttling logic)
     */
    private function shouldRun(): bool
    {
        // Ensure cache directory exists
        $cacheDir = dirname(self::CACHE_FILE);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        // Check last run timestamp
        if (!file_exists(self::CACHE_FILE)) {
            return true;
        }

        $lastRun = (int)file_get_contents(self::CACHE_FILE);
        $hoursSinceLastRun = (time() - $lastRun) / 3600;

        return $hoursSinceLastRun >= self::CHECK_INTERVAL_HOURS;
    }

    /**
     * Update last run timestamp
     */
    private function updateLastRun(): void
    {
        $cacheDir = dirname(self::CACHE_FILE);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        file_put_contents(self::CACHE_FILE, (string)time());
    }

    /**
     * Get info about last run
     */
    public function getLastRunInfo(): array
    {
        if (!file_exists(self::CACHE_FILE)) {
            return [
                'lastRun' => null,
                'nextRun' => null,
                'canRunNow' => true
            ];
        }

        $lastRun = (int)file_get_contents(self::CACHE_FILE);
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
        $result = $this->expungeService->autoExpunge();
        $this->updateLastRun();
        
        return $result;
    }
}