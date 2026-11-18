<?php
declare(strict_types=1);

namespace Tests\Unit\Frontend;

use Frontend\Utils\AssetVersion;
use PHPUnit\Framework\TestCase;

class AssetVersionTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../../frontend/src/Utils/AssetVersion.php';
        $this->file = tempnam(sys_get_temp_dir(), 'asset');
        touch($this->file, 1700000000);
        clearstatcache();
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testReleaseAndModificationTimeAreCombined(): void
    {
        $this->assertSame('3.2.0-1700000000', AssetVersion::of($this->file, '3.2.0'));
    }

    public function testWithoutReleaseOnlyTheModificationTimeIsUsed(): void
    {
        $this->assertSame('1700000000', AssetVersion::of($this->file));
    }

    public function testNewCopyOfTheFileChangesTheValueUnderTheSameRelease(): void
    {
        $before = AssetVersion::of($this->file, '3.2.0');
        touch($this->file, 1700000100);
        clearstatcache();

        $this->assertNotSame($before, AssetVersion::of($this->file, '3.2.0'));
    }

    public function testMissingFileFallsBackToTheRelease(): void
    {
        $this->assertSame('3.2.0', AssetVersion::of('/does/not/exist.css', '3.2.0'));
        $this->assertSame('0', AssetVersion::of('/does/not/exist.css'));
    }
}
