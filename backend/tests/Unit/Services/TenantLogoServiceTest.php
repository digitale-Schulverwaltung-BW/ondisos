<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\PdfLogoResolver;
use App\Services\TenantLogoService;
use PHPUnit\Framework\TestCase;

class TenantLogoServiceTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/ondisos-logo-' . bin2hex(random_bytes(4));
        mkdir($this->base, 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->base);
    }

    private function image(string $type, int $w = 40, int $h = 20): string
    {
        $img = imagecreatetruecolor($w, $h);
        $file = $this->base . '/src-' . bin2hex(random_bytes(3));
        $type === 'png' ? imagepng($img, $file) : imagejpeg($img, $file);
        return $file;
    }

    public function testStoresPngPerTenantAndReplacesOtherFormat(): void
    {
        if (!function_exists('imagejpeg')) {
            $this->markTestSkipped('GD without JPEG support');
        }
        $svc = new TenantLogoService($this->base . '/uploads');

        $this->assertNull($svc->path(2));
        $this->assertNull($svc->saveFile(2, $this->image('png')));
        $this->assertStringEndsWith('/tenant-2/branding/logo.png', (string)$svc->path(2));
        $this->assertNull($svc->path(3), 'other tenants are not affected');

        $this->assertNull($svc->saveFile(2, $this->image('jpeg')));
        $this->assertStringEndsWith('/logo.jpg', (string)$svc->path(2));
        $this->assertFileDoesNotExist($this->base . '/uploads/tenant-2/branding/logo.png');
        $this->assertSame('image/jpeg', $svc->info(2)['mime']);

        $svc->delete(2);
        $this->assertNull($svc->path(2));
    }

    public function testScalesDownWideImages(): void
    {
        $svc = new TenantLogoService($this->base . '/uploads');
        $this->assertNull($svc->saveFile(1, $this->image('png', 1200, 300)));
        $info = $svc->info(1);
        $this->assertSame(TenantLogoService::MAX_WIDTH, $info['width']);
        $this->assertSame(150, $info['height']);
    }

    public function testRejectsNonImagesAndOtherFormats(): void
    {
        $svc = new TenantLogoService($this->base . '/uploads');

        $php = $this->base . '/evil.png';
        file_put_contents($php, '<?php echo 1;');
        $this->assertNotNull($svc->saveFile(1, $php));

        $gif = $this->base . '/a.gif';
        $img = imagecreatetruecolor(5, 5);
        imagegif($img, $gif);
        $this->assertNotNull($svc->saveFile(1, $gif));

        $empty = $this->base . '/empty';
        file_put_contents($empty, '');
        $this->assertNotNull($svc->saveFile(1, $empty));

        $this->assertNull($svc->path(1));
    }

    public function testRejectsOversizedFile(): void
    {
        $svc = new TenantLogoService($this->base . '/uploads');
        $big = $this->image('png');
        file_put_contents($big, str_repeat('x', TenantLogoService::MAX_BYTES + 1), FILE_APPEND);
        $this->assertStringContainsString('zu groß', (string)$svc->saveFile(1, $big));
    }

    public function testResolverOrder(): void
    {
        $svc = new TenantLogoService($this->base . '/uploads');
        $svc->saveFile(5, $this->image('png'));
        $resolver = new PdfLogoResolver($svc);

        $this->assertNull($resolver->resolve(['logo' => false], 'bs', 5)['logo'], 'false = explicitly no logo');
        $this->assertSame('/x/logo.png', $resolver->resolve(['logo' => '/x/logo.png'], 'bs', 5)['logo'], 'platform path wins');
        $this->assertSame($svc->path(5), $resolver->resolve([], 'bs', 5)['logo'], 'school logo');
        $this->assertNull($resolver->resolve([], 'bs', 6)['logo'], 'tenant without a logo');
        $this->assertNull($resolver->resolve([], 'bs', null)['logo']);
    }
}
