<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Simple file-based Rate Limiter
 *
 * Tracks requests per identifier (usually IP address) and enforces limits.
 * Uses filesystem for storage - suitable for single-server deployments.
 */
class RateLimiter
{
    private string $storageDir;
    private int $maxRequests;
    private int $windowSeconds;
    private int $cleanupProbability;

    /**
     * @param string $storageDir Directory to store rate limit data
     * @param int $maxRequests Maximum requests per window
     * @param int $windowSeconds Time window in seconds
     * @param int $cleanupProbability Probability (0-100) to run cleanup on each check
     */
    public function __construct(
        string $storageDir,
        int $maxRequests = 10,
        int $windowSeconds = 60,
        int $cleanupProbability = 10
    ) {
        $this->storageDir = rtrim($storageDir, '/');
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
        $this->cleanupProbability = $cleanupProbability;

        // Ensure storage directory exists
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Check if request is allowed for given identifier
     *
     * @param string $identifier Unique identifier (e.g., IP address)
     * @return bool True if request is allowed, false if rate limit exceeded
     */
    public function isAllowed(string $identifier): bool
    {
        $file = $this->getFilePath($identifier);
        $now = time();
        $cutoff = $now - $this->windowSeconds;

        // Load existing data
        $timestamps = $this->loadTimestamps($file);

        // Remove expired timestamps (outside the window)
        $timestamps = array_filter($timestamps, fn($ts) => $ts > $cutoff);

        // Check if limit exceeded
        if (count($timestamps) >= $this->maxRequests) {
            return false;
        }

        // Add current timestamp
        $timestamps[] = $now;

        // Save updated data
        $this->saveTimestamps($file, $timestamps);

        // Probabilistic cleanup of old files
        $this->maybeCleanup();

        return true;
    }

    /**
     * Get remaining requests for identifier
     *
     * @param string $identifier Unique identifier
     * @return int Number of requests remaining in current window
     */
    public function getRemainingRequests(string $identifier): int
    {
        $file = $this->getFilePath($identifier);
        $now = time();
        $cutoff = $now - $this->windowSeconds;

        $timestamps = $this->loadTimestamps($file);
        $timestamps = array_filter($timestamps, fn($ts) => $ts > $cutoff);

        return max(0, $this->maxRequests - count($timestamps));
    }

    /**
     * Get time until next request is allowed (if rate limited)
     *
     * @param string $identifier Unique identifier
     * @return int Seconds until next request allowed, 0 if not rate limited
     */
    public function getRetryAfter(string $identifier): int
    {
        $file = $this->getFilePath($identifier);
        $now = time();
        $cutoff = $now - $this->windowSeconds;

        $timestamps = $this->loadTimestamps($file);
        $timestamps = array_filter($timestamps, fn($ts) => $ts > $cutoff);

        if (count($timestamps) < $this->maxRequests) {
            return 0;
        }

        // Get oldest timestamp in current window
        sort($timestamps);
        $oldestInWindow = $timestamps[0] ?? $now;

        // Calculate when it will expire
        return max(0, ($oldestInWindow + $this->windowSeconds) - $now);
    }

    /**
     * Reset rate limit for identifier (useful for testing)
     *
     * @param string $identifier Unique identifier
     */
    public function reset(string $identifier): void
    {
        $file = $this->getFilePath($identifier);
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * Get file path for identifier
     *
     * @param string $identifier
     * @return string
     */
    private function getFilePath(string $identifier): string
    {
        // Use hash to avoid filesystem issues with special characters
        $hash = md5($identifier);
        return $this->storageDir . '/rl_' . $hash . '.json';
    }

    /**
     * Load timestamps from file
     *
     * @param string $file
     * @return array<int>
     */
    private function loadTimestamps(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }

        return array_filter($data, 'is_int');
    }

    /**
     * Save timestamps to file
     *
     * @param string $file
     * @param array<int> $timestamps
     */
    private function saveTimestamps(string $file, array $timestamps): void
    {
        $json = json_encode(array_values($timestamps));
        @file_put_contents($file, $json, LOCK_EX);
    }

    /**
     * Probabilistically cleanup old rate limit files
     *
     * Runs with configured probability to avoid I/O overhead on every request
     */
    private function maybeCleanup(): void
    {
        // Random cleanup based on probability
        if (random_int(1, 100) > $this->cleanupProbability) {
            return;
        }

        $cutoff = time() - $this->windowSeconds;
        $files = glob($this->storageDir . '/rl_*.json');

        if (!$files) {
            return;
        }

        foreach ($files as $file) {
            // Check file modification time
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($file);
            }
        }
    }
}
