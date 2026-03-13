<?php
declare(strict_types=1);

namespace Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;

/**
 * Wave 0 stubs for AUTH-01 through AUTH-04.
 *
 * These tests define the contract for the login system.
 * They FAIL now and turn GREEN when Plan 03 implements authentication.
 */
class LoginTest extends TestCase
{
    public function testPlatformAdminLoginSetsIsPlatformAdminSessionFlag(): void
    {
        $this->fail('Not implemented');
    }

    public function testTenantAdminLoginSetsTenantIdSessionFlag(): void
    {
        $this->fail('Not implemented');
    }

    public function testLoginFormHasNoTenantSelectorField(): void
    {
        $this->fail('Not implemented');
    }

    public function testSessionContainsRequiredKeysAfterLogin(): void
    {
        $this->fail('Not implemented');
    }
}
