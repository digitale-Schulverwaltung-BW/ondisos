<?php
declare(strict_types=1);

namespace App\Services;

use App\Utils\ClientIp;

/**
 * Simple file-based Rate Limiter
 *
 * Tracks requests per identifier (usually IP address) and enforces limits.
 * Uses filesystem for storage - suitable for single-server deployments.
 *
 * Read, check and write happen under one exclusive lock on the identifier's file, so parallel
 * requests cannot all pass a limit that only a few of them may pass.
 *
 * Storage failures (directory not writable, lock not available) let the request through: a full or
 * read-only disk must not block every registration and login. They are logged to the PHP error log
 * (once per directory and process), so the operator can see that limits are not in effect.
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
        if (!is_dir($this->storageDir) && !@mkdir($this->storageDir, 0755, true) && !is_dir($this->storageDir)) {
            $this->storageFailure('cannot create the directory');
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
        $result = $this->update($identifier, function (array $timestamps): array {
            if (count($timestamps) >= $this->maxRequests) {
                return [false, null];
            }
            $timestamps[] = time();

            return [true, $timestamps];
        });

        // Probabilistic cleanup of old files
        $this->maybeCleanup();

        return $result ?? true; // storage failure: let through (logged)
    }

    /**
     * Gives back the most recent request of the identifier, e.g. a login attempt that turned out to
     * be successful and should not count towards the limit.
     */
    public function release(string $identifier): void
    {
        $this->update($identifier, function (array $timestamps): array {
            if ($timestamps === []) {
                return [null, null];
            }
            sort($timestamps);
            array_pop($timestamps);

            return [null, $timestamps];
        });
    }

    /**
     * Get remaining requests for identifier
     *
     * @param string $identifier Unique identifier
     * @return int Number of requests remaining in current window
     */
    public function getRemainingRequests(string $identifier): int
    {
        $timestamps = $this->read($identifier);

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
        $now = time();
        $timestamps = $this->read($identifier);

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
     * Generate a robust fingerprint for rate limiting
     *
     * Combines multiple factors to make bypass attacks harder:
     * - IP address (REMOTE_ADDR, or X-Forwarded-For behind a trusted proxy, see ClientIp)
     * - Hashed User-Agent (prevents header injection attacks)
     * - Accept-Language (adds browser fingerprinting)
     *
     * Security: Using SHA-256 instead of MD5 for better collision resistance
     *
     * @param array<string, string> $server The $_SERVER superglobal (injectable for testing)
     * @return string Fingerprint string for rate limiting
     */
    public static function generateFingerprint(array $server): string
    {
        $ip = ClientIp::get($server);
        $userAgent = $server['HTTP_USER_AGENT'] ?? '';
        $acceptLanguage = $server['HTTP_ACCEPT_LANGUAGE'] ?? '';

        // Hash User-Agent and Accept-Language to:
        // 1. Prevent header injection attacks
        // 2. Reduce storage size
        // 3. Add privacy (don't store raw User-Agent strings)
        $userAgentHash = hash('sha256', $userAgent);
        $languageHash = hash('sha256', $acceptLanguage);

        return $ip . ':' . $userAgentHash . ':' . $languageHash;
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
     * Runs $change on the identifier's timestamps (expired ones removed) under an exclusive lock.
     * $change returns [result, new timestamps or null to leave the file as it is].
     *
     * @param callable(list<int>): array{0: mixed, 1: list<int>|null} $change
     * @return mixed the result of $change, or null if the storage failed
     */
    private function update(string $identifier, callable $change): mixed
    {
        $file = $this->getFilePath($identifier);

        // Cleanup may delete the file between our fopen() and flock(); we would then count in a file
        // nobody else sees. Compare inodes after locking and start over in that case.
        for ($try = 0; $try < 3; $try++) {
            $fp = @fopen($file, 'c+');
            if ($fp === false) {
                $this->storageFailure('cannot open a counter file');
                return null;
            }
            if (!flock($fp, LOCK_EX)) {
                fclose($fp);
                $this->storageFailure('cannot lock a counter file');
                return null;
            }

            clearstatcache(true, $file);
            $onDisk = @stat($file);
            $open = fstat($fp);
            if ($onDisk === false || $open === false || $onDisk['ino'] !== $open['ino']) {
                flock($fp, LOCK_UN);
                fclose($fp);
                continue;
            }

            [$result, $timestamps] = $change($this->inWindow((string)stream_get_contents($fp)));

            if ($timestamps !== null) {
                $json = (string)json_encode(array_values($timestamps));
                if (!rewind($fp) || !ftruncate($fp, 0) || fwrite($fp, $json) !== strlen($json) || !fflush($fp)) {
                    $this->storageFailure('cannot write a counter file');
                }
            }

            flock($fp, LOCK_UN);
            fclose($fp);

            return $result;
        }

        $this->storageFailure('counter file replaced repeatedly while locking');
        return null;
    }

    /**
     * Timestamps within the window, read under a shared lock (never a half-written file).
     *
     * @return list<int>
     */
    private function read(string $identifier): array
    {
        $fp = @fopen($this->getFilePath($identifier), 'r');
        if ($fp === false) {
            return []; // no requests yet
        }
        flock($fp, LOCK_SH);
        $content = (string)stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return $this->inWindow($content);
    }

    /** @return list<int> */
    private function inWindow(string $content): array
    {
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return []; // new or damaged file: start counting again
        }
        $cutoff = time() - $this->windowSeconds;

        return array_values(array_filter($data, fn($ts) => is_int($ts) && $ts > $cutoff));
    }

    /** Logs once per directory and process that the limit is not in effect. */
    private function storageFailure(string $what): void
    {
        static $reported = [];
        if (isset($reported[$this->storageDir])) {
            return;
        }
        $reported[$this->storageDir] = true;
        error_log(sprintf(
            'RateLimiter: %s in %s (check permissions and free space). Requests are NOT limited until this is fixed.',
            $what,
            $this->storageDir
        ));
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
