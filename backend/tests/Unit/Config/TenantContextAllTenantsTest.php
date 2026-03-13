<?php
declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\TenantContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit Tests for TenantContext — all-tenants mode extension.
 *
 * Covers the platform admin all-tenants mode added in Phase 2:
 * - initAllTenants() sets isAllTenants() to true without throwing
 * - getTenantId() throws in all-tenants mode (write guard enforced automatically)
 * - initialize() resets all-tenants flag to false
 * - reset() clears both $tenantId and $allTenants
 * - isAllTenants() returns false after initialize()
 * - isAllTenants() returns false on fresh static state
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

    public function testInitAllTenantsSetsFlagWithoutThrowing(): void
    {
        TenantContext::initAllTenants();

        $this->assertTrue(TenantContext::isAllTenants());
    }

    public function testGetTenantIdThrowsInAllTenantsMode(): void
    {
        TenantContext::initAllTenants();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/all.tenants mode/i');

        TenantContext::getTenantId();
    }

    public function testInitializeResetsAllTenantsFlag(): void
    {
        TenantContext::initAllTenants();
        TenantContext::initialize(2);

        $this->assertFalse(TenantContext::isAllTenants());
        $this->assertSame(2, TenantContext::getTenantId());
    }

    public function testResetClearsBothTenantIdAndAllTenantsFlag(): void
    {
        TenantContext::initAllTenants();
        TenantContext::reset();

        $this->assertFalse(TenantContext::isAllTenants());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not initialized/i');

        TenantContext::getTenantId();
    }

    public function testIsAllTenantsReturnsFalseAfterInitialize(): void
    {
        TenantContext::initialize(5);

        $this->assertFalse(TenantContext::isAllTenants());
    }

    public function testIsAllTenantsReturnsFalseOnFreshState(): void
    {
        // No calls made — fresh static state after reset() in setUp()
        $this->assertFalse(TenantContext::isAllTenants());
    }
}
