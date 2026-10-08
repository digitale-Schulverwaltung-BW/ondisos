<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\LoginThrottle;
use PHPUnit\Framework\TestCase;

class LoginThrottleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/login_throttle_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->dir . '/*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->dir);
    }

    public function testBlocksAccountAfterConfiguredFailures(): void
    {
        $t = new LoginThrottle($this->dir, 3, 100);
        for ($i = 0; $i < 3; $i++) {
            $this->assertFalse($t->isBlocked('10.0.0.1', 'admin'));
            $t->recordFailure('10.0.0.1', 'admin');
        }

        $this->assertTrue($t->isBlocked('10.0.0.1', 'admin'));
        $this->assertGreaterThan(0, $t->retryAfter('10.0.0.1', 'admin'));
    }

    public function testOtherIpCannotLockAccountOfSomeoneElse(): void
    {
        $t = new LoginThrottle($this->dir, 3, 100);
        for ($i = 0; $i < 3; $i++) {
            $t->recordFailure('10.0.0.66', 'admin');
        }

        $this->assertFalse($t->isBlocked('10.0.0.1', 'admin'));
    }

    public function testUsernameIsCaseInsensitive(): void
    {
        $t = new LoginThrottle($this->dir, 2, 100);
        $t->recordFailure('10.0.0.1', 'Admin');
        $t->recordFailure('10.0.0.1', ' admin ');

        $this->assertTrue($t->isBlocked('10.0.0.1', 'ADMIN'));
    }

    public function testIpLimitBlocksGuessingManyUsernames(): void
    {
        $t = new LoginThrottle($this->dir, 5, 3);
        foreach (['a', 'b', 'c'] as $u) {
            $t->recordFailure('10.0.0.1', $u);
        }

        $this->assertTrue($t->isBlocked('10.0.0.1', 'neuer-benutzer'));
    }

    public function testSuccessResetsAccountCounter(): void
    {
        $t = new LoginThrottle($this->dir, 3, 100);
        $t->recordFailure('10.0.0.1', 'admin');
        $t->recordFailure('10.0.0.1', 'admin');
        $t->recordSuccess('10.0.0.1', 'admin');
        $t->recordFailure('10.0.0.1', 'admin');

        $this->assertFalse($t->isBlocked('10.0.0.1', 'admin'));
    }
}
