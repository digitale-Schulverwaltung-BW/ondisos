<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\PdfTemplateRenderer;
use PHPUnit\Framework\TestCase;

class PdfLogoPathTest extends TestCase
{
    public function testAssetDirectoryIsAllowed(): void
    {
        $dir = __DIR__ . '/../../../templates/pdf/assets';
        $files = glob($dir . '/*.*') ?: [];
        if ($files === []) {
            $this->markTestSkipped('Keine Datei in templates/pdf/assets');
        }

        $this->assertTrue(PdfTemplateRenderer::isAllowedLogoPath($files[0]));
    }

    public function testPathOutsideAllowedDirectoriesIsRejected(): void
    {
        $this->assertFalse(PdfTemplateRenderer::isAllowedLogoPath(__FILE__));
        $this->assertFalse(PdfTemplateRenderer::isAllowedLogoPath('/etc/hosts'));
    }

    public function testTraversalOutOfAssetDirectoryIsRejected(): void
    {
        $this->assertFalse(PdfTemplateRenderer::isAllowedLogoPath(
            __DIR__ . '/../../../templates/pdf/assets/../../../composer.json'
        ));
    }

    public function testMissingFileIsRejected(): void
    {
        $this->assertFalse(PdfTemplateRenderer::isAllowedLogoPath('/nonexistent/logo.png'));
    }
}
