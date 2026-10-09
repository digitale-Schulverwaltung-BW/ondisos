<?php
declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\TenantContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * There is deliberately no "all tenants" mode: without an active tenant the context
 * throws instead of falling through to every school's data.
 */
class TenantContextAllTenantsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TenantContext::reset();
    }

    public function testFreshStateIsNotInitializedAndThrows(): void
    {
        $this->assertFalse(TenantContext::isInitialized());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not initialized/i');

        TenantContext::getTenantId();
    }

    public function testInitializeActivatesTheTenant(): void
    {
        TenantContext::initialize(5);

        $this->assertTrue(TenantContext::isInitialized());
        $this->assertSame(5, TenantContext::getTenantId());
    }

    public function testResetClearsTheTenant(): void
    {
        TenantContext::initialize(5);
        TenantContext::reset();

        $this->assertFalse(TenantContext::isInitialized());
    }

    public function testAllTenantsModeNoLongerExists(): void
    {
        $this->assertFalse(method_exists(TenantContext::class, 'initAllTenants'));
        $this->assertFalse(method_exists(TenantContext::class, 'isAllTenants'));
    }
}
