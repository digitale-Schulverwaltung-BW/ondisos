<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Config\TenantContext;
use PHPUnit\Framework\TestCase;

/**
 * Wave 0 stubs for ISOL-03 — tenant_id field in audit log entries.
 *
 * These tests define the contract for AuditLogger tenant awareness.
 * They FAIL now and turn GREEN when Plan 06 implements audit log changes.
 */
class AuditLoggerTenantIdTest extends TestCase
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

    public function testLogEntryContainsTenantIdWhenContextInitialized(): void
    {
        $this->fail('Not implemented');
    }

    public function testLogEntryHasNullTenantIdWhenContextUninitialized(): void
    {
        $this->fail('Not implemented');
    }
}
