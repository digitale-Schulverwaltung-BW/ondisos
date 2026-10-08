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
 * forms.php:    sign over "forms:{tenant slug}"
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
     * Validate a signature over a short canonical message (used by forms.php: "forms:<tenant slug>").
     * Binding the message to the endpoint and the slug means a signature made for one purpose is useless for another.
     */
    public function validateMessage(string $message, string $providedSig): bool
    {
        if ($providedSig === '' || !$this->secretIsUsable()) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $message, $this->secret), $providedSig);
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
     * Canonical string of signature format v2. Binds the purpose (an upload signature is useless for
     * submit.php), the time and a one-time nonce to the payload, so a captured request cannot be replayed.
     *
     * Payloads: submit = sha256(raw body); upload = "{anmeldung_id}\n{fieldname}\n{filename}\n{sha256(file content)}";
     * forms = tenant slug.
     */
    public static function canonicalV2(string $purpose, string $timestamp, string $nonce, string $payload): string
    {
        return "v2\n" . $purpose . "\n" . $timestamp . "\n" . $nonce . "\n" . $payload;
    }

    /** Validate a v2 signature (see canonicalV2()). Freshness and replay are checked by SignatureVerifier. */
    public function validateV2(string $purpose, string $payload, string $timestamp, string $nonce, string $providedSig): bool
    {
        if ($providedSig === '' || $timestamp === '' || $nonce === '' || !$this->secretIsUsable()) {
            return false;
        }

        $expected = hash_hmac('sha256', self::canonicalV2($purpose, $timestamp, $nonce, $payload), $this->secret);

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
