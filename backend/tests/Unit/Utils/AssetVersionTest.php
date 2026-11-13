<?php

declare(strict_types=1);

namespace Tests\Unit\Utils;

use App\Utils\AssetVersion;
use PHPUnit\Framework\TestCase;

final class AssetVersionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/asset-version-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/assets/mods', 0777, true);
        file_put_contents($this->dir . '/assets/mods/app.js', '');
        file_put_contents($this->dir . '/assets/mods/ui.js', '');
        file_put_contents($this->dir . '/assets/mods/style.css', '');
        touch($this->dir . '/assets/mods/app.js', 1_700_000_000);
        touch($this->dir . '/assets/mods/ui.js', 1_700_000_100);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/assets/mods/*') ?: []);
        rmdir($this->dir . '/assets/mods');
        rmdir($this->dir . '/assets');
        rmdir($this->dir);
    }

    public function testUrlCarriesTheModificationTime(): void
    {
        $this->assertSame('assets/mods/app.js?v=1700000000', (new AssetVersion($this->dir))->url('assets/mods/app.js'));
    }

    public function testUrlOfAMissingFileStaysUnversioned(): void
    {
        $this->assertSame('assets/mods/nope.js', (new AssetVersion($this->dir))->url('assets/mods/nope.js'));
    }

    public function testImportMapListsEveryModuleOfTheDirectoryAndNothingElse(): void
    {
        $this->assertSame([
            './assets/mods/app.js' => './assets/mods/app.js?v=1700000000',
            './assets/mods/ui.js' => './assets/mods/ui.js?v=1700000100',
        ], (new AssetVersion($this->dir))->importMap(['assets/mods/']));
    }

    public function testImportMapTagIsAnImportMapWithTheNonce(): void
    {
        $tag = (new AssetVersion($this->dir))->importMapTag(['assets/mods'], 'abc');
        $this->assertStringStartsWith('<script type="importmap" nonce="abc">{"imports":{"./assets/mods/app.js":"./assets/mods/app.js?v=1700000000"', $tag);
        $this->assertStringEndsWith('</script>', $tag);
    }

    public function testImportMapTagOfAnEmptyDirectoryIsAnEmptyObject(): void
    {
        $this->assertStringContainsString('{"imports":{}}', (new AssetVersion($this->dir))->importMapTag(['assets/none'], 'n'));
    }
}
