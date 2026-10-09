<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\TwoFactorSession;
use PHPUnit\Framework\TestCase;

class TwoFactorSessionTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function begin(string $mode = TwoFactorSession::MODE_CODE, int $now = 1000): void
    {
        TwoFactorSession::begin('tenant:7', 'schule', false, 3, $mode, $now);
    }

    public function testBeginStoresPendingStateButNoLogin(): void
    {
        $this->begin();
        $pending = TwoFactorSession::current(1001);

        $this->assertSame('tenant:7', $pending['admin_key']);
        $this->assertSame(3, $pending['tenant_id']);
        $this->assertSame('code', $pending['mode']);
        $this->assertArrayNotHasKey('admin_logged_in', $_SESSION, 'the intermediate state must never look like a login');
    }

    public function testStateExpiresAfterFiveMinutes(): void
    {
        $this->begin();
        $this->assertNotNull(TwoFactorSession::current(1000 + 300));
        $this->assertNull(TwoFactorSession::current(1000 + 301));
        $this->assertArrayNotHasKey(TwoFactorSession::KEY, $_SESSION);
    }

    public function testManipulatedStateIsDropped(): void
    {
        $_SESSION[TwoFactorSession::KEY] = ['admin_key' => 'platform:x', 'username' => 'x', 'mode' => 'login', 'started' => 1000];
        $this->assertNull(TwoFactorSession::current(1001));

        $_SESSION[TwoFactorSession::KEY] = 'yes';
        $this->assertNull(TwoFactorSession::current(1001));

        $_SESSION[TwoFactorSession::KEY] = true;
        $this->assertNull(TwoFactorSession::current(1001));
    }

    public function testFifthWrongCodeEndsTheState(): void
    {
        $this->begin(TwoFactorSession::MODE_CODE, time());
        for ($i = 1; $i < TwoFactorSession::MAX_ATTEMPTS; $i++) {
            $this->assertTrue(TwoFactorSession::recordFailedAttempt(), "attempt $i");
        }
        $this->assertFalse(TwoFactorSession::recordFailedAttempt());
        $this->assertNull(TwoFactorSession::current());
    }

    public function testFailedAttemptWithoutStateIsFalse(): void
    {
        $this->assertFalse(TwoFactorSession::recordFailedAttempt());
    }

    public function testClear(): void
    {
        $this->begin();
        TwoFactorSession::clear();
        $this->assertNull(TwoFactorSession::current(1001));
    }
}
