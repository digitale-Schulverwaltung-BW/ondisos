<?php
declare(strict_types=1);

namespace App\Services;

/**
 * PDF Token Service
 *
 * Generates and validates HMAC-based tokens for PDF downloads.
 * Tokens are self-validating (no database storage required).
 *
 * Token Format: base64(id:timestamp:hmac)
 * Example: MTIzOjE3MDYzNjQwMDA6YWJjZGVmZ2hpams=
 *          Decoded: "123:1706364000:abcdefghijk"
 */
class PdfTokenService
{
    private const DEFAULT_LIFETIME = 1800; // 30 minutes
    private const HASH_ALGO = 'sha256';

    private string $secretKey;

    public function __construct()
    {
        $this->secretKey = getenv('PDF_TOKEN_SECRET') ?: '';

        if (empty($this->secretKey)) {
            throw new \RuntimeException(
                'PDF_TOKEN_SECRET not configured in .env. Generate with: openssl rand -hex 32'
            );
        }

        if (strlen($this->secretKey) < 32) {
            throw new \RuntimeException(
                'PDF_TOKEN_SECRET must be at least 32 characters long'
            );
        }
    }

    /**
     * Generate a PDF download token for an Anmeldung ID
     *
     * @param int $anmeldungId The Anmeldung ID
     * @param int $lifetime Token lifetime in seconds (default: 1800 = 30 min)
     * @return string Base64-encoded token
     */
    public function generateToken(int $anmeldungId, int $lifetime = self::DEFAULT_LIFETIME): string
    {
        $timestamp = time();
        $hmac = $this->generateHmac($anmeldungId, $timestamp, $lifetime);

        // Format: id:timestamp:lifetime:hmac
        $tokenData = sprintf('%d:%d:%d:%s', $anmeldungId, $timestamp, $lifetime, $hmac);

        return base64_encode($tokenData);
    }

    /**
     * Validate a token and return the Anmeldung ID
     *
     * @param string $token The token to validate
     * @return int|null The Anmeldung ID if valid, null otherwise
     */
    public function validateToken(string $token): ?int
    {
        try {
            // Decode base64
            $decoded = base64_decode($token, true);

            if ($decoded === false) {
                return null;
            }

            // Parse token parts
            $parts = explode(':', $decoded);

            if (count($parts) !== 4) {
                return null;
            }

            [$id, $timestamp, $lifetime, $providedHmac] = $parts;

            // Validate parts are numeric
            if (!is_numeric($id) || !is_numeric($timestamp) || !is_numeric($lifetime)) {
                return null;
            }

            $id = (int)$id;
            $timestamp = (int)$timestamp;
            $lifetime = (int)$lifetime;

            // Check if token is expired
            if ($this->isExpired($timestamp, $lifetime)) {
                return null;
            }

            // Verify HMAC
            $expectedHmac = $this->generateHmac($id, $timestamp, $lifetime);

            if (!hash_equals($expectedHmac, $providedHmac)) {
                return null;
            }

            return $id;

        } catch (\Throwable $e) {
            // Any error in validation = invalid token
            error_log('PDF token validation error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if a token is expired based on timestamp and lifetime
     *
     * @param int $timestamp Token creation timestamp
     * @param int $lifetime Token lifetime in seconds
     * @return bool True if expired
     */
    private function isExpired(int $timestamp, int $lifetime): bool
    {
        return (time() - $timestamp) >= $lifetime;
    }

    /**
     * Generate HMAC for token verification
     *
     * @param int $id Anmeldung ID
     * @param int $timestamp Token creation timestamp
     * @param int $lifetime Token lifetime
     * @return string HMAC hash
     */
    private function generateHmac(int $id, int $timestamp, int $lifetime): string
    {
        $data = sprintf('%d:%d:%d', $id, $timestamp, $lifetime);

        return hash_hmac(self::HASH_ALGO, $data, $this->secretKey);
    }

    /**
     * Get the default token lifetime
     *
     * @return int Lifetime in seconds
     */
    public static function getDefaultLifetime(): int
    {
        return self::DEFAULT_LIFETIME;
    }
}
