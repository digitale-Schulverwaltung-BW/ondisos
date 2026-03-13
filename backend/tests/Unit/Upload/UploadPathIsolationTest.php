<?php
declare(strict_types=1);

namespace Tests\Unit\Upload;

use App\Config\TenantContext;
use PHPUnit\Framework\TestCase;

/**
 * Wave 0 stubs for ISOL-02 — tenant-scoped upload directory paths.
 *
 * These tests define the contract for upload path isolation.
 * They FAIL now and turn GREEN when Plan 07 implements upload path scoping.
 */
class UploadPathIsolationTest extends TestCase
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

    public function testUploadDirectoryIncludesTenantId(): void
    {
        $this->fail('Not implemented');
    }

    public function testCrosstenantPathAccessIsRejected(): void
    {
        $this->fail('Not implemented');
    }
}
