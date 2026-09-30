<?php
declare(strict_types=1);

namespace App\Services;

/**
 * HmacValidator — timing-safe HMAC-SHA256 request authentication.
 *
 * Used by submit.php and upload.php to verify that each incoming request
 * was signed with the tenant's api_secret. All comparisons use hash_equals()
 * to prevent timing-based secret-oracle attacks.
 *
 * submit.php:   sign over raw request body (php://input)
 * upload.php:   sign over canonical string "{anmeldung_id}:{fieldname}:{filename}"
 */
class HmacValidator
{
    public function __construct(private readonly string $secret) {}

    /**
     * Validate a raw-body HMAC signature (used by submit.php).
     *
     * Returns false immediately when $providedSig is empty so that a missing
     * X-Signature header is treated as an explicit rejection rather than
     * accidentally comparing two empty strings.
     */
    public function validate(string $body, string $providedSig): bool
    {
        if ($providedSig === '' || !$this->secretIsUsable()) {
            return false;
        }

        $expected = hash_hmac('sha256', $body, $this->secret);

        return hash_equals($expected, $providedSig);
    }

    /**
     * Validate an upload HMAC signature using the canonical string.
     *
     * Canonical string: "{anmeldung_id}:{fieldname}:{original_filename}"
     * This avoids signing the raw multipart body (which is fragile) and
     * instead signs the three identifying fields that uniquely describe
     * the upload request.
     */
    public function validateUploadSignature(
        string $anmeldungId,
        string $fieldname,
        string $filename,
        string $providedSig,
    ): bool {
        if ($providedSig === '' || !$this->secretIsUsable()) {
            return false;
        }

        $canonical = $anmeldungId . ':' . $fieldname . ':' . $filename;
        $expected  = hash_hmac('sha256', $canonical, $this->secret);

        return hash_equals($expected, $providedSig);
    }

    /**
     * Refuse to authenticate against a publicly known secret (see SecretPolicy).
     * Logged without the secret so a misconfigured tenant is diagnosable.
     */
    private function secretIsUsable(): bool
    {
        if (SecretPolicy::isAcceptable($this->secret)) {
            return true;
        }

        error_log('HmacValidator: tenant api_secret is a known placeholder/default — rejecting request. Set a real secret.');

        return false;
    }
}
