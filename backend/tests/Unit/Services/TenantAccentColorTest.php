<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\PdfLogoResolver;
use App\Services\PdfTemplateRenderer;
use App\Services\TenantAccentColor;
use App\Services\TenantLogoService;
use PHPUnit\Framework\TestCase;

class TenantAccentColorTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/accent-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base . '/tenant-*/branding/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->base . '/tenant-*/branding') ?: [] as $d) {
            @rmdir($d);
        }
        foreach (glob($this->base . '/tenant-*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->base);
    }

    /** @return array<string,array{0:mixed,1:?string}> */
    public static function normalizeCases(): array
    {
        return [
            'long'            => ['#3498DB', '#3498db'],
            'without hash'    => ['3498db', '#3498db'],
            'short'           => ['#abc', '#aabbcc'],
            'blanks'          => ['  #FFF ', '#ffffff'],
            'too long'        => ['#3498db0', null],
            'not hex'         => ['#12345g', null],
            'css injection'   => ['#fff; } body { display:none', null],
            'empty'           => ['', null],
            'not a string'    => [123456, null],
        ];
    }

    /** @dataProvider normalizeCases */
    public function testNormalize(mixed $in, ?string $expected): void
    {
        $this->assertSame($expected, TenantAccentColor::normalize($in));
    }

    public function testSaveGetDeleteIsolatedPerTenant(): void
    {
        $svc = new TenantAccentColor($this->base);
        $this->assertNull($svc->get(1));
        $this->assertNull($svc->save(1, '#C0392B'));
        $this->assertSame('#c0392b', $svc->get(1));
        $this->assertNull($svc->get(2));
        $svc->delete(1);
        $this->assertNull($svc->get(1));
    }

    public function testSaveRejectsInvalidInputAndKeepsOldValue(): void
    {
        $svc = new TenantAccentColor($this->base);
        $svc->save(1, '#112233');
        $this->assertNotNull($svc->save(1, 'rot'));
        $this->assertSame('#112233', $svc->get(1));
    }

    public function testResolverSetsTenantColorEvenWithoutLogoAndIgnoresConfigValue(): void
    {
        $svc = new TenantAccentColor($this->base);
        $svc->save(7, '#00aa00');
        $resolver = new PdfLogoResolver(new TenantLogoService($this->base), $svc);

        $this->assertSame('#00aa00', $resolver->resolve(['logo' => false], 'bs', 7)['accent_color']);
        $this->assertSame('#00aa00', $resolver->resolve(['accent_color' => '#ff0000'], 'bs', 7)['accent_color']);
        $this->assertNull($resolver->resolve([], 'bs', 8)['accent_color']);
        $this->assertNull($resolver->resolve([], 'bs', null)['accent_color']);
    }

    public function testRendererReplacesDefaultAccentInStyles(): void
    {
        $dir = $this->base . '-tpl';
        mkdir($dir);
        file_put_contents($dir . '/styles.css', '.a{border-left:4px solid #3498db}.b{color:#2c3e50}');
        $m = new \ReflectionMethod(PdfTemplateRenderer::class, 'loadStyles');
        $r = new PdfTemplateRenderer($dir);

        $this->assertSame('.a{border-left:4px solid #c0392b}.b{color:#2c3e50}', $m->invoke($r, '#c0392b'));
        $this->assertStringContainsString('#3498db', $m->invoke($r, null));

        unlink($dir . '/styles.css');
        rmdir($dir);
    }
}
