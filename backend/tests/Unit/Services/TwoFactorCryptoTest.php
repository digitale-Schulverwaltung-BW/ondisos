<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\TwoFactorCrypto;
use PHPUnit\Framework\TestCase;

class TwoFactorCryptoTest extends TestCase
{
    private function crypto(): TwoFactorCrypto
    {
        return new TwoFactorCrypto(str_repeat('k', 32));
    }

    protected function tearDown(): void
    {
        unset($_ENV['TWO_FACTOR_KEY']);
    }

    public function testRoundtrip(): void
    {
        $crypto = $this->crypto();
        $this->assertSame('JBSWY3DPEHPK3PXP', $crypto->decrypt($crypto->encrypt('JBSWY3DPEHPK3PXP')));
    }

    public function testCiphertextDiffersEachTimeAndHidesPlaintext(): void
    {
        $crypto = $this->crypto();
        $a = $crypto->encrypt('JBSWY3DPEHPK3PXP');
        $this->assertNotSame($a, $crypto->encrypt('JBSWY3DPEHPK3PXP'));
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $a);
    }

    public function testManipulatedCiphertextIsRejected(): void
    {
        $crypto = $this->crypto();
        $raw = base64_decode($crypto->encrypt('secret'), true);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] ^ "\x01";
        $this->expectException(\RuntimeException::class);
        $crypto->decrypt(base64_encode($raw));
    }

    public function testWrongKeyIsRejected(): void
    {
        $encrypted = $this->crypto()->encrypt('secret');
        $this->expectException(\RuntimeException::class);
        (new TwoFactorCrypto(str_repeat('x', 32)))->decrypt($encrypted);
    }

    public function testGarbageIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->crypto()->decrypt('not base64 !!');
    }

    public function testWrongKeyLengthIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TwoFactorCrypto('short');
    }

    public function testFromEnvironmentNeedsAValidKey(): void
    {
        unset($_ENV['TWO_FACTOR_KEY']);
        $this->assertFalse(TwoFactorCrypto::isConfigured());

        $_ENV['TWO_FACTOR_KEY'] = 'zz';
        $this->assertFalse(TwoFactorCrypto::isConfigured());

        $_ENV['TWO_FACTOR_KEY'] = bin2hex(random_bytes(32));
        $this->assertTrue(TwoFactorCrypto::isConfigured());
        $crypto = TwoFactorCrypto::fromEnvironment();
        $this->assertSame('x', $crypto->decrypt($crypto->encrypt('x')));
    }
}
