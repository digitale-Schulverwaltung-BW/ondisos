<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\TwoFactorPolicy;
use PHPUnit\Framework\TestCase;

class TwoFactorPolicyTest extends TestCase
{
    public function testPlatformModeRequiresPlatformAdminsOnlyInMultiTenantOperation(): void
    {
        $multi = new TwoFactorPolicy('platform', true, 10);
        $this->assertTrue($multi->isRequired(true));
        $this->assertFalse($multi->isRequired(false));

        $single = new TwoFactorPolicy('platform', false, 10);
        $this->assertFalse($single->isRequired(true));
    }

    public function testTenantsAndAllModes(): void
    {
        $tenants = new TwoFactorPolicy('tenants', true, 10);
        $this->assertTrue($tenants->isRequired(false));
        $this->assertFalse($tenants->isRequired(true));

        $all = new TwoFactorPolicy('all', false, 10);
        $this->assertTrue($all->isRequired(true));
        $this->assertTrue($all->isRequired(false));
    }

    public function testUnknownModeFallsBackToPlatform(): void
    {
        $policy = new TwoFactorPolicy('bogus', true, 10);
        $this->assertTrue($policy->isRequired(true));
        $this->assertFalse($policy->isRequired(false));
    }

    public function testEnrolledAdminAlwaysGetsCodePrompt(): void
    {
        $policy = new TwoFactorPolicy('platform', true, 10);
        $this->assertSame('code', $policy->decide(false, true, 0), 'tenant admin enrolled voluntarily');
        $this->assertSame('code', $policy->decide(true, true, 99));
    }

    public function testNotRequiredAndNotEnrolledLogsInPlainly(): void
    {
        $policy = new TwoFactorPolicy('platform', true, 10);
        $this->assertSame('none', $policy->decide(false, false, 0));
        $this->assertSame('none', (new TwoFactorPolicy('platform', false, 10))->decide(true, false, 0));
    }

    public function testGraceLoginsThenSetupRequired(): void
    {
        $policy = new TwoFactorPolicy('platform', true, 10);
        for ($used = 0; $used < 10; $used++) {
            $this->assertSame('grace', $policy->decide(true, false, $used), "login " . ($used + 1));
        }
        $this->assertSame('setup_required', $policy->decide(true, false, 10), 'login 11');
        $this->assertSame('setup_required', $policy->decide(true, false, 50));
    }

    public function testZeroGraceLoginsMeansImmediateSetup(): void
    {
        $this->assertSame('setup_required', (new TwoFactorPolicy('platform', true, 0))->decide(true, false, 0));
    }

    public function testOffDisablesEverythingEvenForEnrolledAdmins(): void
    {
        $policy = new TwoFactorPolicy('off', true, 10);
        $this->assertTrue($policy->isDisabled());
        $this->assertSame('none', $policy->decide(true, true, 0));
        $this->assertSame('none', $policy->decide(true, false, 99));
    }
}
