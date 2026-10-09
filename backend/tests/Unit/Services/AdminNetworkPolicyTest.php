<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AdminNetworkPolicy;
use PHPUnit\Framework\TestCase;

class AdminNetworkPolicyTest extends TestCase
{
    /** @param array<int, string> $tenants */
    private function policy(string $platform, array $tenants = []): AdminNetworkPolicy
    {
        return new AdminNetworkPolicy($platform, static fn (int $id): string => $tenants[$id] ?? '');
    }

    public function testEmptyListsAllowEverything(): void
    {
        $policy = $this->policy('', [1 => '']);
        $this->assertTrue($policy->isAllowed(null, true, '203.0.113.9'));
        $this->assertTrue($policy->isAllowed(1, false, '203.0.113.9'));
        $this->assertTrue($policy->isAllowedWithoutLogin('203.0.113.9'));
    }

    public function testPlatformAdminIsCheckedAgainstPlatformListOnly(): void
    {
        $policy = $this->policy('10.10.0.0/24', [1 => '192.0.2.0/24']);
        $this->assertTrue($policy->isAllowed(null, true, '10.10.0.5'));
        $this->assertFalse($policy->isAllowed(null, true, '192.0.2.5'));
    }

    public function testTenantAdminIsCheckedAgainstOwnTenantList(): void
    {
        $policy = $this->policy('10.10.0.0/24', [1 => '192.0.2.0/24', 2 => '198.51.100.0/24']);
        $this->assertTrue($policy->isAllowed(1, false, '192.0.2.77'));
        $this->assertFalse($policy->isAllowed(1, false, '198.51.100.7'));
        $this->assertFalse($policy->isAllowed(1, false, '10.10.0.5'), 'platform list does not apply to tenant admins');
        $this->assertTrue($policy->isAllowed(2, false, '198.51.100.7'));
    }

    public function testTenantWithoutListIsUnrestrictedEvenIfOthersAreNot(): void
    {
        $policy = $this->policy('', [1 => '192.0.2.0/24']);
        $this->assertTrue($policy->isAllowed(2, false, '203.0.113.1'));
    }

    public function testNoTenantAndNoPlatformFlagIsDenied(): void
    {
        $this->assertFalse($this->policy('')->isAllowed(null, false, '10.0.0.1'));
    }

    public function testWithoutLoginUsesPlatformList(): void
    {
        $policy = $this->policy("10.10.0.0/24\n2001:db8::/32");
        $this->assertTrue($policy->isAllowedWithoutLogin('10.10.0.200'));
        $this->assertTrue($policy->isAllowedWithoutLogin('2001:db8::1'));
        $this->assertFalse($policy->isAllowedWithoutLogin('10.10.1.1'));
    }

    public function testListWithOnlyInvalidEntriesDeniesEverything(): void
    {
        $policy = $this->policy('10.10.0.0/99, foo');
        $this->assertFalse($policy->isAllowed(null, true, '10.10.0.5'));
        $this->assertFalse($policy->isAllowedWithoutLogin('10.10.0.5'));
    }

    public function testUnknownClientIpIsDeniedWhenListIsSet(): void
    {
        $this->assertFalse($this->policy('0.0.0.0/0')->isAllowedWithoutLogin('unknown'));
    }
}
