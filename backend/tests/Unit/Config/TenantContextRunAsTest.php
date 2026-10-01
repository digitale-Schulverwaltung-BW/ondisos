<?php
declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\TenantContext;
use PHPUnit\Framework\TestCase;

class TenantContextRunAsTest extends TestCase
{
    protected function setUp(): void
    {
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testRunsAsTheOtherTenantAndRestoresTheOne(): void
    {
        TenantContext::initialize(1);
        $seen = TenantContext::runAs(7, static fn () => TenantContext::getTenantId());

        $this->assertSame(7, $seen);
        $this->assertSame(1, TenantContext::getTenantId());
    }

    public function testRestoresAllTenantsMode(): void
    {
        TenantContext::initAllTenants();
        $inside = TenantContext::runAs(3, static fn () => [TenantContext::isAllTenants(), TenantContext::getTenantId()]);

        $this->assertSame([false, 3], $inside);
        $this->assertTrue(TenantContext::isAllTenants());
    }

    public function testRestoresTheUninitializedState(): void
    {
        TenantContext::runAs(3, static fn () => null);
        $this->expectException(\RuntimeException::class);
        TenantContext::getTenantId();
    }

    public function testRestoresEvenWhenTheCallbackThrows(): void
    {
        TenantContext::initialize(1);
        try {
            TenantContext::runAs(9, static function (): never {
                throw new \LogicException('boom');
            });
            $this->fail('expected exception');
        } catch (\LogicException) {
            $this->assertSame(1, TenantContext::getTenantId());
        }
    }

    public function testNestedRunAsUnwindsStepByStep(): void
    {
        TenantContext::initialize(1);
        $trace = TenantContext::runAs(2, static function () {
            $inner = TenantContext::runAs(3, static fn () => TenantContext::getTenantId());
            return [$inner, TenantContext::getTenantId()];
        });
        $this->assertSame([3, 2], $trace);
        $this->assertSame(1, TenantContext::getTenantId());
    }

    public function testReturnsTheCallbackResult(): void
    {
        $this->assertSame('x', TenantContext::runAs(1, static fn () => 'x'));
    }
}
