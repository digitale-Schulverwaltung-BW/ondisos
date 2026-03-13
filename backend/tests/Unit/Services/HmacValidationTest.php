<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * Wave 0 stubs for MGMT-03 — HMAC request validation.
 *
 * These tests define the contract for the HmacValidator service.
 * They FAIL now and turn GREEN when Plan 05 implements HMAC validation.
 */
class HmacValidationTest extends TestCase
{
    public function testCorrectHmacSignatureIsAccepted(): void
    {
        $this->fail('Not implemented');
    }

    public function testWrongSecretProducesRejection(): void
    {
        $this->fail('Not implemented');
    }

    public function testMissingSignatureHeaderProducesRejection(): void
    {
        $this->fail('Not implemented');
    }
}
