<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\RecoveryCodes;
use PHPUnit\Framework\TestCase;

class RecoveryCodesTest extends TestCase
{
    public function testGeneratesTenUniqueCodesInReadableFormat(): void
    {
        $codes = RecoveryCodes::generate();
        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[a-hj-km-np-z2-9]{4}-[a-hj-km-np-z2-9]{4}-[a-hj-km-np-z2-9]{4}$/', $code);
        }
    }

    public function testHashIgnoresCaseDashesAndSpaces(): void
    {
        $hash = RecoveryCodes::hash('k7m2-9xqp-4rtb');
        $this->assertSame($hash, RecoveryCodes::hash('K7M2 9XQP 4RTB'));
        $this->assertSame($hash, RecoveryCodes::hash('k7m29xqp4rtb'));
        $this->assertSame(64, strlen($hash));
    }

    public function testDifferentCodesHaveDifferentHashes(): void
    {
        $this->assertNotSame(RecoveryCodes::hash('k7m2-9xqp-4rtb'), RecoveryCodes::hash('k7m2-9xqp-4rtc'));
    }

    public function testLooksLikeCodeTellsItApartFromTotp(): void
    {
        $this->assertTrue(RecoveryCodes::looksLikeCode('k7m2-9xqp-4rtb'));
        $this->assertFalse(RecoveryCodes::looksLikeCode('123456'));
        $this->assertFalse(RecoveryCodes::looksLikeCode('123 456'));
    }
}
