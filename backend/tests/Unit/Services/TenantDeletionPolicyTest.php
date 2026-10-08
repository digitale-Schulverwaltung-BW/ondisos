<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\TenantDeletionPolicy;
use App\Services\TenantUploadCleaner;
use PHPUnit\Framework\TestCase;

class TenantDeletionPolicyTest extends TestCase
{
    public function testInactiveEmptyTenantMayBeDeleted(): void
    {
        $this->assertNull(TenantDeletionPolicy::blocker(2, false, 0, 0));
    }

    public function testDefaultTenantIsNeverDeletable(): void
    {
        $this->assertNotNull(TenantDeletionPolicy::blocker(1, false, 0, 0));
    }

    public function testActiveTenantIsBlocked(): void
    {
        $this->assertStringContainsString('aktiv', (string)TenantDeletionPolicy::blocker(2, true, 0, 0));
    }

    public function testTenantWithFormsIsBlocked(): void
    {
        $this->assertStringContainsString('Formular', (string)TenantDeletionPolicy::blocker(2, false, 3, 0));
    }

    public function testTenantWithSubmissionsIsBlocked(): void
    {
        $this->assertStringContainsString('Anmeldung', (string)TenantDeletionPolicy::blocker(2, false, 0, 5));
    }

    public function testUploadCleanerRemovesOnlyTheTenantDirectory(): void
    {
        $base = sys_get_temp_dir() . '/ondisos-uploads-' . bin2hex(random_bytes(4));
        mkdir($base . '/tenant-7/branding', 0777, true);
        mkdir($base . '/tenant-8', 0777, true);
        file_put_contents($base . '/tenant-7/branding/accent.txt', '#112233');
        file_put_contents($base . '/tenant-7/1_a.pdf', 'x');

        $this->assertTrue((new TenantUploadCleaner($base))->remove(7));
        $this->assertDirectoryDoesNotExist($base . '/tenant-7');
        $this->assertDirectoryExists($base . '/tenant-8');

        rmdir($base . '/tenant-8');
        rmdir($base);
    }

    public function testUploadCleanerTreatsMissingDirectoryAsDone(): void
    {
        $this->assertTrue((new TenantUploadCleaner(sys_get_temp_dir() . '/ondisos-none'))->remove(9));
    }
}
