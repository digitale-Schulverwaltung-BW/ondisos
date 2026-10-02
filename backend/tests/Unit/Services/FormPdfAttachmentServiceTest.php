<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\FormPdfAttachmentService;
use App\Services\PdfLogoResolver;
use Mpdf\Mpdf;
use PHPUnit\Framework\TestCase;

class FormPdfAttachmentServiceTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/attach-' . bin2hex(random_bytes(4));
        mkdir($this->base, 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->base);
    }

    /** A small valid PDF with the given number of A4 pages (portrait; the last one landscape if $landscapeLast). */
    private function makePdf(int $pages, bool $landscapeLast = false): string
    {
        $m = new Mpdf(['tempDir' => sys_get_temp_dir()]);
        for ($i = 1; $i <= $pages; $i++) {
            $m->AddPage($landscapeLast && $i === $pages ? 'L' : 'P');
            $m->WriteHTML('<p>Seite ' . $i . '</p>');
        }
        $file = $this->base . '/src-' . bin2hex(random_bytes(3)) . '.pdf';
        file_put_contents($file, $m->Output('', \Mpdf\Output\Destination::STRING_RETURN));
        return $file;
    }

    private function pageCount(string $pdfBytes): int
    {
        $f = $this->base . '/count.pdf';
        file_put_contents($f, $pdfBytes);
        return (new Mpdf(['tempDir' => sys_get_temp_dir()]))->setSourceFile($f);
    }

    public function testSaveInfoDeleteAndTenantIsolation(): void
    {
        $svc = new FormPdfAttachmentService($this->base);
        $this->assertNull($svc->path(1, 'gs'));

        $this->assertNull($svc->saveFile(1, 'gs', $this->makePdf(2), 'Info Blatt.pdf'));
        $info = $svc->info(1, 'gs');
        $this->assertSame(['name' => 'Info Blatt.pdf', 'bytes' => (int)filesize((string)$svc->path(1, 'gs')), 'pages' => 2], $info);
        $this->assertNull($svc->path(2, 'gs'), 'another tenant has none');
        $this->assertNull($svc->path(1, 'bs'), 'another form has none');

        $svc->delete(1, 'gs');
        $this->assertNull($svc->path(1, 'gs'));
        $this->assertNull($svc->info(1, 'gs'));
    }

    public function testRejectsNonPdfEmptyTooManyPagesAndBadKey(): void
    {
        $svc = new FormPdfAttachmentService($this->base);

        $txt = $this->base . '/x.pdf';
        file_put_contents($txt, 'das ist kein pdf');
        $this->assertStringContainsString('keine PDF', (string)$svc->saveFile(1, 'gs', $txt));

        $empty = $this->base . '/empty.pdf';
        file_put_contents($empty, '');
        $this->assertStringContainsString('leer', (string)$svc->saveFile(1, 'gs', $empty));

        $fake = $this->base . '/fake.pdf';
        file_put_contents($fake, "%PDF-1.4\nnot really a pdf\n");
        $this->assertStringContainsString('nicht eingebunden', (string)$svc->saveFile(1, 'gs', $fake));

        $this->assertStringContainsString('zu viele Seiten', (string)$svc->saveFile(1, 'gs', $this->makePdf(FormPdfAttachmentService::MAX_PAGES + 1)));

        $this->assertStringContainsString('Ungültig', (string)$svc->saveFile(1, '../evil', $this->makePdf(1)));
        $this->assertNull($svc->path(1, '../evil'));
        $this->assertNull($svc->path(1, 'gs'), 'nothing was stored by the failed attempts');
    }

    public function testAppendToAddsAllPagesWithOwnOrientationAndToleratesBrokenFiles(): void
    {
        $att = $this->makePdf(2, true);

        $m = new Mpdf(['tempDir' => sys_get_temp_dir()]);
        $m->WriteHTML('<p>Bestätigung</p>');
        FormPdfAttachmentService::appendTo($m, $att);
        $this->assertSame(3, $this->pageCount($m->Output('', \Mpdf\Output\Destination::STRING_RETURN)));

        $m2 = new Mpdf(['tempDir' => sys_get_temp_dir()]);
        $m2->WriteHTML('<p>Bestätigung</p>');
        FormPdfAttachmentService::appendTo($m2, null);
        FormPdfAttachmentService::appendTo($m2, $this->base . '/does-not-exist.pdf');
        $broken = $this->base . '/broken.pdf';
        file_put_contents($broken, '%PDF-1.4 kaputt');
        FormPdfAttachmentService::appendTo($m2, $broken);
        $this->assertSame(1, $this->pageCount($m2->Output('', \Mpdf\Output\Destination::STRING_RETURN)));
    }

    public function testResolverSetsAttachmentPathFromTenantAndFormOnly(): void
    {
        $svc = new FormPdfAttachmentService($this->base);
        $svc->saveFile(5, 'gs', $this->makePdf(1));
        $resolver = new PdfLogoResolver(attachments: $svc);

        $this->assertSame($svc->path(5, 'gs'), $resolver->resolve([], 'gs', 5)['attachment_path']);
        $this->assertNull($resolver->resolve([], 'gs', 6)['attachment_path']);
        $this->assertNull($resolver->resolve([], 'bs', 5)['attachment_path']);
        $this->assertNull($resolver->resolve([], 'gs', null)['attachment_path']);
        $this->assertSame($svc->path(5, 'gs'), $resolver->resolve(['attachment_path' => '/etc/passwd'], 'gs', 5)['attachment_path'], 'a path from the stored config is never used');
        $this->assertNull($resolver->resolve(['attachment_path' => '/etc/passwd'], 'bs', 5)['attachment_path']);
    }
}
