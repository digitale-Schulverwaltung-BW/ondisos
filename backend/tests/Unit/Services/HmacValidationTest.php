<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\HmacValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HmacValidator — MGMT-03 / FORM-04.
 *
 * Covers:
 *  - Correct HMAC signature accepted
 *  - Wrong secret produces rejection
 *  - Empty/missing signature produces rejection
 *  - Upload canonical string validation (anmeldung_id:fieldname:filename)
 */
class HmacValidationTest extends TestCase
{
    public function testCorrectHmacSignatureIsAccepted(): void
    {
        $secret = 'test-secret-key';
        $body   = '{"form_key":"bs","data":{"name":"Test"}}';
        $sig    = hash_hmac('sha256', $body, $secret);

        $validator = new HmacValidator($secret);

        $this->assertTrue($validator->validate($body, $sig));
    }

    public function testWrongSecretProducesRejection(): void
    {
        $body      = '{"form_key":"bs","data":{"name":"Test"}}';
        $goodSig   = hash_hmac('sha256', $body, 'correct-secret');

        $validator = new HmacValidator('wrong-secret');

        $this->assertFalse($validator->validate($body, $goodSig));
    }

    public function testMissingSignatureHeaderProducesRejection(): void
    {
        $body      = '{"form_key":"bs","data":{"name":"Test"}}';
        $validator = new HmacValidator('any-secret');

        $this->assertFalse($validator->validate($body, ''));
    }

    public function testUploadCanonicalStringValidation(): void
    {
        $secret    = 'upload-secret';
        $canonical = '42:photo:test.jpg';
        $sig       = hash_hmac('sha256', $canonical, $secret);

        $validator = new HmacValidator($secret);

        $this->assertTrue(
            $validator->validateUploadSignature('42', 'photo', 'test.jpg', $sig)
        );
    }

    public function testUploadWrongSecretProducesRejection(): void
    {
        $canonical = '42:photo:test.jpg';
        $sig       = hash_hmac('sha256', $canonical, 'correct-secret');

        $validator = new HmacValidator('wrong-secret');

        $this->assertFalse(
            $validator->validateUploadSignature('42', 'photo', 'test.jpg', $sig)
        );
    }

    public function testUploadMissingSignatureProducesRejection(): void
    {
        $validator = new HmacValidator('any-secret');

        $this->assertFalse(
            $validator->validateUploadSignature('42', 'photo', 'test.jpg', '')
        );
    }
}
