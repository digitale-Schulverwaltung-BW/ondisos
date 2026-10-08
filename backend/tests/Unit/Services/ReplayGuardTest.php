<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ReplayGuard;
use PHPUnit\Framework\TestCase;

class ReplayGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/replayguard_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testSecondUseWithinTtlIsRejected(): void
    {
        $guard = new ReplayGuard($this->dir, 600);

        $this->assertTrue($guard->firstUse('1|abc'));
        $this->assertFalse($guard->firstUse('1|abc'));
    }

    public function testDifferentKeysAreIndependent(): void
    {
        $guard = new ReplayGuard($this->dir, 600);

        $this->assertTrue($guard->firstUse('1|abc'));
        $this->assertTrue($guard->firstUse('2|abc'));
        $this->assertTrue($guard->firstUse('1|def'));
    }

    public function testExpiredEntryCanBeUsedAgain(): void
    {
        $guard = new ReplayGuard($this->dir, 600);
        $guard->firstUse('1|abc');
        foreach (glob($this->dir . '/n_*') as $f) {
            touch($f, time() - 700);
        }

        $this->assertTrue($guard->firstUse('1|abc'));
    }
}
