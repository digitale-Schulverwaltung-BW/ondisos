<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\TotpService;
use PHPUnit\Framework\TestCase;

class TotpServiceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private TotpService $totp;
    private string $secret;

    protected function setUp(): void
    {
        $this->totp = new TotpService();
        $this->secret = $this->totp->generateSecret();
    }

    public function testSecretIsBase32WithRecommendedLength(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $this->secret);
        $this->assertNotSame($this->secret, $this->totp->generateSecret());
    }

    public function testProvisioningUriCarriesIssuerAccountAndSecret(): void
    {
        $uri = $this->totp->provisioningUri($this->secret, 'schulleitung');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('schulleitung', $uri);
        $this->assertStringContainsString('issuer=ondisos', $uri);
        $this->assertStringContainsString('secret=' . $this->secret, $uri);
    }

    public function testCurrentCodeIsAcceptedAndReturnsItsStep(): void
    {
        $code = $this->totp->codeAt($this->secret, self::NOW);
        $this->assertSame(intdiv(self::NOW, 30), $this->totp->verify($this->secret, $code, null, self::NOW));
    }

    public function testCodeWithSpacesIsAccepted(): void
    {
        $code = $this->totp->codeAt($this->secret, self::NOW);
        $spaced = substr($code, 0, 3) . ' ' . substr($code, 3);
        $this->assertNotNull($this->totp->verify($this->secret, $spaced, null, self::NOW));
    }

    public function testNeighbouringStepsAreAccepted(): void
    {
        $this->assertNotNull($this->totp->verify($this->secret, $this->totp->codeAt($this->secret, self::NOW - 30), null, self::NOW));
        $this->assertNotNull($this->totp->verify($this->secret, $this->totp->codeAt($this->secret, self::NOW + 30), null, self::NOW));
    }

    public function testCodesOutsideTheWindowAreRejected(): void
    {
        $this->assertNull($this->totp->verify($this->secret, $this->totp->codeAt($this->secret, self::NOW - 90), null, self::NOW));
        $this->assertNull($this->totp->verify($this->secret, $this->totp->codeAt($this->secret, self::NOW + 90), null, self::NOW));
    }

    public function testWrongCodeIsRejected(): void
    {
        $code = $this->totp->codeAt($this->secret, self::NOW);
        $wrong = str_pad((string)(((int)$code + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);
        $this->assertNull($this->totp->verify($this->secret, $wrong, null, self::NOW));
    }

    public function testMalformedInputIsRejected(): void
    {
        foreach (['', 'abcdef', '12345', '1234567', '12 34'] as $input) {
            $this->assertNull($this->totp->verify($this->secret, $input, null, self::NOW), $input);
        }
    }

    public function testReplayOfAnAcceptedStepIsRejected(): void
    {
        $code = $this->totp->codeAt($this->secret, self::NOW);
        $step = $this->totp->verify($this->secret, $code, null, self::NOW);
        $this->assertNotNull($step);
        $this->assertNull($this->totp->verify($this->secret, $code, $step, self::NOW));
    }

    public function testOlderStepThanTheLastAcceptedIsRejected(): void
    {
        $older = $this->totp->codeAt($this->secret, self::NOW - 30);
        $newerStep = intdiv(self::NOW, 30);
        $this->assertNull($this->totp->verify($this->secret, $older, $newerStep, self::NOW));
    }

    public function testNextStepAfterReplayGuardIsAccepted(): void
    {
        $lastStep = intdiv(self::NOW, 30);
        $next = $this->totp->codeAt($this->secret, self::NOW + 30);
        $this->assertSame($lastStep + 1, $this->totp->verify($this->secret, $next, $lastStep, self::NOW));
    }

    public function testCodeOfAnotherSecretIsRejected(): void
    {
        $other = $this->totp->generateSecret();
        $this->assertNull($this->totp->verify($this->secret, $this->totp->codeAt($other, self::NOW), null, self::NOW));
    }
}
