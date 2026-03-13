<?php
declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\TenantContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit Tests for TenantContext
 *
 * Tests the static singleton that provides request-scoped tenant identity.
 * Covers:
 * - RuntimeException thrown when getTenantId() called before initialize()
 * - Correct value returned after initialize()
 * - Last-write-wins behavior when initialize() called twice
 * - reset() restores uninitialized state
 */
class TenantContextTest extends TestCase
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

    public function testGetTenantIdThrowsWhenNotInitialized(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not initialized/i');

        TenantContext::getTenantId();
    }

    public function testGetTenantIdReturnsValueAfterInitialize(): void
    {
        TenantContext::initialize(1);

        $this->assertSame(1, TenantContext::getTenantId());
    }

    public function testInitializeTwiceLastWriteWins(): void
    {
        TenantContext::initialize(1);
        TenantContext::initialize(42);

        $this->assertSame(42, TenantContext::getTenantId());
    }

    public function testResetRestoresUninitializedState(): void
    {
        TenantContext::initialize(1);
        TenantContext::reset();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not initialized/i');

        TenantContext::getTenantId();
    }
}
