<?php
declare(strict_types=1);

namespace Tests\Unit\Upload;

use App\Config\TenantContext;
use App\Controllers\DownloadController;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ISOL-02 — tenant-scoped upload directory paths.
 *
 * Verifies that:
 * - Upload paths include the tenant ID subdirectory.
 * - DownloadController rejects cross-tenant file path access.
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

    /**
     * When TenantContext is initialized with tenant 3, the upload directory
     * path should end with "tenant-3".
     */
    public function testUploadDirectoryIncludesTenantId(): void
    {
        TenantContext::initialize(3);

        $controller = new DownloadController();
        $allowedDir = $controller->getAllowedUploadDir();

        $this->assertStringEndsWith('/tenant-3', $allowedDir);
        $this->assertStringContainsString('tenant-3', $allowedDir);
    }

    /**
     * When tenant 1 is active, a file path that points to tenant-2's
     * directory should be rejected by the path validation.
     *
     * We create a real directory and file for tenant-2 so realpath() resolves,
     * then verify isWithinAllowedDir() returns false for tenant-1.
     */
    public function testCrosstenantPathAccessIsRejected(): void
    {
        // Set up a temporary uploads directory structure
        $tmpBase = sys_get_temp_dir() . '/upload_test_' . uniqid();
        $tenant1Dir = $tmpBase . '/tenant-1';
        $tenant2Dir = $tmpBase . '/tenant-2';

        mkdir($tenant1Dir, 0755, true);
        mkdir($tenant2Dir, 0755, true);

        // Create a file in tenant-2's directory
        $tenant2File = $tenant2Dir . '/secret.pdf';
        file_put_contents($tenant2File, 'tenant-2 data');

        try {
            $tenant2RealPath = realpath($tenant2File);
            $this->assertNotFalse($tenant2RealPath, 'Test setup: tenant-2 file should exist');

            // Tenant 1 is the active tenant — allowed dir is tenant-1
            $tenant1RealDir = realpath($tenant1Dir);
            $this->assertNotFalse($tenant1RealDir, 'Test setup: tenant-1 dir should exist');

            // Directly test the path validation logic
            TenantContext::initialize(1);
            $controller = new DownloadController();

            // Cross-tenant access: tenant-2 file path vs tenant-1 allowed dir
            $isAllowed = $controller->isWithinAllowedDir($tenant2RealPath, $tenant1RealDir);
            $this->assertFalse($isAllowed, 'Cross-tenant file access must be rejected');

            // Confirm same-tenant access IS allowed
            $tenant1File = $tenant1Dir . '/valid.pdf';
            file_put_contents($tenant1File, 'tenant-1 data');
            $tenant1RealPath = realpath($tenant1File);
            $isAllowed = $controller->isWithinAllowedDir($tenant1RealPath, $tenant1RealDir);
            $this->assertTrue($isAllowed, 'Same-tenant file access must be allowed');
        } finally {
            // Cleanup
            @unlink($tenant2File);
            @unlink($tenant1Dir . '/valid.pdf');
            @rmdir($tenant1Dir);
            @rmdir($tenant2Dir);
            @rmdir($tmpBase);
        }
    }

    /**
     * upload.php must verify that the target Anmeldung belongs to the authenticated
     * tenant before storing anything (a valid HMAC only proves possession of the
     * tenant secret). The endpoint is a script, so guard the ordering on source level;
     * the behaviour itself is covered by the live check documented in the commit.
     */
    public function testUploadEndpointChecksAnmeldungOwnershipBeforeStoringFile(): void
    {
        $src = file_get_contents(__DIR__ . '/../../../public/api/upload.php');

        $ownership = strpos($src, '(new AnmeldungRepository())->findById($anmeldungId)');
        $move      = strpos($src, 'move_uploaded_file');

        $this->assertNotFalse($ownership, 'upload.php must look up the Anmeldung tenant-scoped');
        $this->assertNotFalse($move);
        $this->assertLessThan($move, $ownership, 'ownership check must precede move_uploaded_file()');
        $this->assertStringContainsString("'Anmeldung nicht gefunden', 404", $src);
    }
}
