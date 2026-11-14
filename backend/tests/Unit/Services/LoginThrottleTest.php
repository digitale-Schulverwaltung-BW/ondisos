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
            $this->assertTrue($t->tryAttempt('10.0.0.1', 'admin'));
        }

        $this->assertTrue($t->isBlocked('10.0.0.1', 'admin'));
        $this->assertGreaterThan(0, $t->retryAfter('10.0.0.1', 'admin'));
    }

    public function testOtherIpCannotLockAccountOfSomeoneElse(): void
    {
        $t = new LoginThrottle($this->dir, 3, 100);
        for ($i = 0; $i < 3; $i++) {
            $t->tryAttempt('10.0.0.66', 'admin');
        }

        $this->assertFalse($t->isBlocked('10.0.0.1', 'admin'));
    }

    public function testUsernameIsCaseInsensitive(): void
    {
        $t = new LoginThrottle($this->dir, 2, 100);
        $t->tryAttempt('10.0.0.1', 'Admin');
        $t->tryAttempt('10.0.0.1', ' admin ');

        $this->assertTrue($t->isBlocked('10.0.0.1', 'ADMIN'));
    }

    public function testIpLimitBlocksGuessingManyUsernames(): void
    {
        $t = new LoginThrottle($this->dir, 5, 3);
        foreach (['a', 'b', 'c'] as $u) {
            $t->tryAttempt('10.0.0.1', $u);
        }

        $this->assertTrue($t->isBlocked('10.0.0.1', 'neuer-benutzer'));
    }

    public function testSuccessResetsAccountCounter(): void
    {
        $t = new LoginThrottle($this->dir, 3, 100);
        $t->tryAttempt('10.0.0.1', 'admin');
        $t->tryAttempt('10.0.0.1', 'admin');
        $t->recordSuccess('10.0.0.1', 'admin');
        $t->tryAttempt('10.0.0.1', 'admin');

        $this->assertFalse($t->isBlocked('10.0.0.1', 'admin'));
    }

    public function testBlockedAttemptIsRefusedAndNotCounted(): void
    {
        $t = new LoginThrottle($this->dir, 2, 3);
        $t->tryAttempt('10.0.0.1', 'admin');
        $t->tryAttempt('10.0.0.1', 'admin');

        $this->assertFalse($t->tryAttempt('10.0.0.1', 'admin'));
        $this->assertTrue($t->tryAttempt('10.0.0.1', 'other'), 'the refused attempt used no IP slot');
    }

    public function testSuccessfulLoginsDoNotFillTheIpCounter(): void
    {
        // Many colleagues behind one school IP log in successfully
        $t = new LoginThrottle($this->dir, 5, 3);
        foreach (['a', 'b', 'c', 'd', 'e'] as $u) {
            $this->assertTrue($t->tryAttempt('10.0.0.1', $u));
            $t->recordSuccess('10.0.0.1', $u);
        }

        $this->assertFalse($t->isBlocked('10.0.0.1', 'f'));
    }

    public function testCancelledAttemptDoesNotCount(): void
    {
        $t = new LoginThrottle($this->dir, 1, 100);
        $t->tryAttempt('10.0.0.1', 'admin');
        $t->cancelAttempt('10.0.0.1', 'admin');

        $this->assertTrue($t->tryAttempt('10.0.0.1', 'admin'));
    }
}
