<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Config\TenantContext;
use App\Services\UploadCleanupService;
use PHPUnit\Framework\TestCase;

class UploadCleanupServiceTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/upload-cleanup-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/tenant-1', 0777, true);
        mkdir($this->base . '/tenant-2', 0777, true);
        TenantContext::reset();
        TenantContext::initialize(1);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        $this->rmrf($this->base);
        parent::tearDown();
    }

    private function rmrf(string $p): void
    {
        if (is_link($p) || is_file($p)) {
            @unlink($p);
            return;
        }
        foreach (glob($p . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (basename($f) !== '.' && basename($f) !== '..') {
                $this->rmrf($f);
            }
        }
        @rmdir($p);
    }

    private function touchFile(string $rel): void
    {
        file_put_contents($this->base . '/' . $rel, 'x');
    }

    public function testDeletesAllFilesOfTheAnmeldung(): void
    {
        $this->touchFile('tenant-1/5_zeugnis.pdf');
        $this->touchFile('tenant-1/5_foto.jpg');

        $this->assertSame(2, (new UploadCleanupService($this->base))->deleteForAnmeldung(5));
        $this->assertFileDoesNotExist($this->base . '/tenant-1/5_zeugnis.pdf');
        $this->assertFileDoesNotExist($this->base . '/tenant-1/5_foto.jpg');
    }

    public function testPrefixMatchIsExact(): void
    {
        $this->touchFile('tenant-1/5_a.pdf');
        $this->touchFile('tenant-1/50_a.pdf');
        $this->touchFile('tenant-1/15_a.pdf');
        $this->touchFile('tenant-1/5a.pdf');

        $this->assertSame(1, (new UploadCleanupService($this->base))->deleteForAnmeldung(5));
        $this->assertFileExists($this->base . '/tenant-1/50_a.pdf');
        $this->assertFileExists($this->base . '/tenant-1/15_a.pdf');
        $this->assertFileExists($this->base . '/tenant-1/5a.pdf');
    }

    public function testOtherTenantsFilesAreUntouched(): void
    {
        $this->touchFile('tenant-1/5_a.pdf');
        $this->touchFile('tenant-2/5_a.pdf');

        (new UploadCleanupService($this->base))->deleteForAnmeldung(5);

        $this->assertFileDoesNotExist($this->base . '/tenant-1/5_a.pdf');
        $this->assertFileExists($this->base . '/tenant-2/5_a.pdf');
    }

    public function testFilesInUploadsRootAreUntouched(): void
    {
        $this->touchFile('5_legacy.pdf');

        $this->assertSame(0, (new UploadCleanupService($this->base))->deleteForAnmeldung(5));
        $this->assertFileExists($this->base . '/5_legacy.pdf');
    }

    public function testSymlinkIsRemovedButTargetOutsideSurvives(): void
    {
        $outside = $this->base . '/outside.txt';
        file_put_contents($outside, 'secret');
        symlink($outside, $this->base . '/tenant-1/5_link.pdf');

        $this->assertSame(1, (new UploadCleanupService($this->base))->deleteForAnmeldung(5));
        $this->assertFileDoesNotExist($this->base . '/tenant-1/5_link.pdf');
        $this->assertFileExists($outside);
    }

    public function testSubdirectoriesAreNotTraversedOrDeleted(): void
    {
        mkdir($this->base . '/tenant-1/5_dir');
        file_put_contents($this->base . '/tenant-1/5_dir/inner.txt', 'x');

        $this->assertSame(0, (new UploadCleanupService($this->base))->deleteForAnmeldung(5));
        $this->assertFileExists($this->base . '/tenant-1/5_dir/inner.txt');
    }

    public function testTenantDirSymlinkedOutsideBaseIsIgnored(): void
    {
        $this->rmrf($this->base . '/tenant-2');
        $elsewhere = sys_get_temp_dir() . '/elsewhere-' . bin2hex(random_bytes(6));
        mkdir($elsewhere);
        file_put_contents($elsewhere . '/5_a.pdf', 'x');
        symlink($elsewhere, $this->base . '/tenant-2');
        TenantContext::reset();
        TenantContext::initialize(2);

        try {
            $this->assertSame(0, (new UploadCleanupService($this->base))->deleteForAnmeldung(5));
            $this->assertFileExists($elsewhere . '/5_a.pdf');
        } finally {
            $this->rmrf($elsewhere);
        }
    }

    public function testMissingTenantDirReturnsZero(): void
    {
        TenantContext::reset();
        TenantContext::initialize(99);

        $this->assertSame(0, (new UploadCleanupService($this->base))->deleteForAnmeldung(5));
    }

    public function testMissingBaseDirReturnsZero(): void
    {
        $this->assertSame(0, (new UploadCleanupService($this->base . '/nope'))->deleteForAnmeldung(5));
    }

    public function testInvalidIdDeletesNothing(): void
    {
        $this->touchFile('tenant-1/0_a.pdf');
        $this->touchFile('tenant-1/-1_a.pdf');

        $svc = new UploadCleanupService($this->base);
        $this->assertSame(0, $svc->deleteForAnmeldung(0));
        $this->assertSame(0, $svc->deleteForAnmeldung(-1));
        $this->assertFileExists($this->base . '/tenant-1/0_a.pdf');
    }

    public function testUninitializedContextDoesNotThrowOrDelete(): void
    {
        TenantContext::reset();
        $this->touchFile('tenant-1/5_a.pdf');

        $this->assertSame(0, (new UploadCleanupService($this->base))->deleteForAnmeldung(5));
        $this->assertFileExists($this->base . '/tenant-1/5_a.pdf');
    }

    public function testAllTenantsModeDoesNotDelete(): void
    {
        TenantContext::reset();
        TenantContext::initAllTenants();
        $this->touchFile('tenant-1/5_a.pdf');

        $this->assertSame(0, (new UploadCleanupService($this->base))->deleteForAnmeldung(5));
        $this->assertFileExists($this->base . '/tenant-1/5_a.pdf');
    }
}
