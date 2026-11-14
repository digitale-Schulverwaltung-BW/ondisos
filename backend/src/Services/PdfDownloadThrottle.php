<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Rate limit of the public PDF download (pdf/download.php), checked before mPDF renders anything.
 *
 * A download link is a reusable bearer token, and every request renders a new PDF. Three counters
 * keep one link (or one visitor with many links) from tying up the backend for all schools:
 *
 *  - per entry:   one registration's confirmation (a person downloads it once or twice)
 *  - per visitor: the pseudonymous X-Ondisos-Client id of the frontend; skipped without it, because
 *                 an older frontend would make all visitors of a school share one counter
 *  - per tenant:  upper bound for one school, so that the others keep their share of the CPU
 *
 * Narrow counters are checked first, so a request they refuse does not use up the school's budget.
 * Each counter has its own directory: RateLimiter's cleanup deletes files older than its own window.
 */
final class PdfDownloadThrottle
{
    public const ENTRY_WINDOW  = 600;
    public const CLIENT_WINDOW = 600;
    public const TENANT_WINDOW = 60;

    private RateLimiter $perEntry;
    private RateLimiter $perClient;
    private RateLimiter $perTenant;

    public function __construct(
        string $storageDir,
        int $entryMax = 10,
        int $clientMax = 30,
        int $tenantMax = 60,
        int $cleanupProbability = 10
    ) {
        $storageDir = rtrim($storageDir, '/');
        $this->perEntry  = new RateLimiter($storageDir . '/entry', $entryMax, self::ENTRY_WINDOW, $cleanupProbability);
        $this->perClient = new RateLimiter($storageDir . '/client', $clientMax, self::CLIENT_WINDOW, $cleanupProbability);
        $this->perTenant = new RateLimiter($storageDir . '/tenant', $tenantMax, self::TENANT_WINDOW, $cleanupProbability);
    }

    /** Limits from the environment (PDF_RATE_LIMIT_*); null when RATE_LIMIT_ENABLED=false. */
    public static function fromEnv(string $storageDir): ?self
    {
        if (!filter_var($_ENV['RATE_LIMIT_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        return new self(
            $storageDir,
            max(1, (int)($_ENV['PDF_RATE_LIMIT_ENTRY_MAX'] ?? 10)),
            max(1, (int)($_ENV['PDF_RATE_LIMIT_CLIENT_MAX'] ?? 30)),
            max(1, (int)($_ENV['PDF_RATE_LIMIT_TENANT_MAX'] ?? 60))
        );
    }

    /**
     * Counts the request and returns null if it may render, otherwise the seconds to wait (Retry-After).
     *
     * @param string|null $clientId pseudonymous visitor id (ClientIdentity::clientId()), null if unknown
     */
    public function check(int $entryId, int $tenantId, ?string $clientId): ?int
    {
        $checks = [[$this->perEntry, 'entry:' . $entryId]];
        if ($clientId !== null) {
            $checks[] = [$this->perClient, 'client:' . $tenantId . ':' . $clientId];
        }
        $checks[] = [$this->perTenant, 'tenant:' . $tenantId];

        foreach ($checks as [$limiter, $key]) {
            if (!$limiter->isAllowed($key)) {
                return max(1, $limiter->getRetryAfter($key));
            }
        }

        return null;
    }
}
