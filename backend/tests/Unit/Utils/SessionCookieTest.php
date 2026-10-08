<?php

declare(strict_types=1);

namespace Tests\Unit\Utils;

use App\Utils\SessionCookie;
use PHPUnit\Framework\TestCase;

class SessionCookieTest extends TestCase
{
    public function testSecureFlagFollowsConfiguration(): void
    {
        $this->assertTrue(SessionCookie::params(true)['secure']);
        $this->assertFalse(SessionCookie::params(false)['secure']);
    }

    public function testHttpOnlyAndSameSiteAreAlwaysSet(): void
    {
        $params = SessionCookie::params(false);

        $this->assertTrue($params['httponly']);
        $this->assertSame('Lax', $params['samesite']);
    }
}
