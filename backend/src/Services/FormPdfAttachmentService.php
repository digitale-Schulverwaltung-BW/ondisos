<?php
declare(strict_types=1);

namespace App\Services;

use App\Forms\Identifiers;
use Mpdf\Mpdf;

/**
 * A PDF a school attaches to the confirmation PDF of one form (e.g. an information sheet): one file per form.
 *
 * Stored as uploads/tenant-<id>/forms/<form key>/attachment.pdf (+ attachment.json with the original name).
 * The upload is checked by content, not by name or the browser's claim, and by actually importing every page the way the
 * generator will (so a file that is rejected here can never break a download later). Only page content is imported
 * into the confirmation, nothing active (scripts, forms, links to files) travels with it.
 *
 * The free PDF importer cannot read PDFs with compressed cross-reference tables (typical for PDF 1.5+ saved by
 * some office programs) or encrypted ones; those are rejected with a hint how to convert them.
 */
class FormPdfAttachmentService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_PAGES = 20;

    private readonly string $uploadsBase;

    public function __construct(?string $uploadsBase = null)
    {
        $this->uploadsBase = $uploadsBase ?? __DIR__ . '/../../uploads';
    }

    /** Absolute path of the form's attachment, or null if it has none. */
    public function path(int $tenantId, string $formKey): ?string
    {
        $file = $this->file($tenantId, $formKey);
        return $file !== null && is_file($file) ? $file : null;
    }

    /** @return array{name:string,bytes:int,pages:int}|null */
    public function info(int $tenantId, string $formKey): ?array
    {
        $file = $this->path($tenantId, $formKey);
        if ($file === null) {
            return null;
        }
        $metaFile = $this->metaFile($tenantId, $formKey);
        $meta  = is_file($metaFile) ? json_decode((string)@file_get_contents($metaFile), true) : null;
        $pages = is_array($meta) && is_int($meta['pages'] ?? null) ? $meta['pages'] : 0;
        $name  = is_array($meta) && is_string($meta['name'] ?? null) && $meta['name'] !== '' ? $meta['name'] : 'anhang.pdf';
        return ['name' => $name, 'bytes' => (int)filesize($file), 'pages' => $pages];
    }

    /**
     * @param array<string,mixed> $upload one entry of $_FILES
     * @return string|null error message, null on success
     */
    public function saveUpload(int $tenantId, string $formKey, array $upload): ?string
    {
        $err = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return 'Die Datei ist zu groß (höchstens 5 MB).';
        }
        if ($err !== UPLOAD_ERR_OK) {
            return 'Es wurde keine Datei hochgeladen.';
        }
        $tmp = (string)($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return 'Es wurde keine Datei hochgeladen.';
        }
        return $this->saveFile($tenantId, $formKey, $tmp, (string)($upload['name'] ?? ''));
    }

    /** Same as saveUpload() for a file already on disk (also used by tests). */
    public function saveFile(int $tenantId, string $formKey, string $source, string $originalName = ''): ?string
    {
        if (!Identifiers::isValidFormKey($formKey)) {
            return 'Ungültiger Formular-Schlüssel.';
        }
        $bytes = @filesize($source);
        if ($bytes === false || $bytes === 0) {
            return 'Die Datei ist leer.';
        }
        if ($bytes > self::MAX_BYTES) {
            return 'Die Datei ist zu groß (höchstens 5 MB).';
        }
        $head = (string)@file_get_contents($source, false, null, 0, 1024);
        if (!str_contains($head, '%PDF-')) {
            return 'Das ist keine PDF-Datei.';
        }

        $pages = 0;
        $problem = $this->check($source, $pages);
        if ($problem !== null) {
            return $problem;
        }

        $target = $this->file($tenantId, $formKey);
        $dir    = dirname((string)$target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('FormPdfAttachmentService: cannot create ' . $dir);
            return 'Die Datei konnte nicht gespeichert werden.';
        }
        $tmp = $target . '.tmp' . bin2hex(random_bytes(4));
        if (!@copy($source, $tmp) || !@rename($tmp, (string)$target)) {
            @unlink($tmp);
            error_log('FormPdfAttachmentService: cannot write ' . $target);
            return 'Die Datei konnte nicht gespeichert werden.';
        }
        $name = preg_replace('/[^\p{L}\p{N}._ -]/u', '_', basename($originalName)) ?? '';
        @file_put_contents($this->metaFile($tenantId, $formKey), json_encode(['name' => mb_substr($name, 0, 120), 'pages' => $pages], JSON_UNESCAPED_UNICODE));
        return null;
    }

    public function delete(int $tenantId, string $formKey): void
    {
        foreach ([$this->file($tenantId, $formKey), $this->metaFile($tenantId, $formKey)] as $f) {
            if ($f !== null && is_file($f)) {
                @unlink($f);
            }
        }
    }

    /**
     * Append all pages of the form's attachment to the document, each in its own size and orientation.
     * Never throws: a broken or missing file must not take the confirmation down with it (it is logged instead).
     */
    public static function appendTo(Mpdf $mpdf, ?string $path): void
    {
        if ($path === null || !is_file($path)) {
            return;
        }
        try {
            $count = $mpdf->setSourceFile($path);
            for ($i = 1; $i <= min($count, self::MAX_PAGES); $i++) {
                $tpl  = $mpdf->importPage($i);
                $size = $mpdf->getTemplateSize($tpl);
                $w = (float)$size['width'];
                $h = (float)$size['height'];
                $mpdf->AddPageByArray(['orientation' => $w > $h ? 'L' : 'P', 'sheet-size' => [$w, $h], 'margin-left' => 0, 'margin-right' => 0, 'margin-top' => 0, 'margin-bottom' => 0, 'margin-header' => 0, 'margin-footer' => 0]);
                $mpdf->useTemplate($tpl, 0, 0, $w, $h);
            }
        } catch (\Throwable $e) {
            error_log('FormPdfAttachmentService: attachment skipped (' . $path . '): ' . $e->getMessage());
        }
    }

    /** Import every page like the generator does; returns an error message or null. */
    private function check(string $file, int &$pages): ?string
    {
        try {
            $mpdf  = new Mpdf(['tempDir' => sys_get_temp_dir(), 'mode' => 'utf-8']);
            $pages = $mpdf->setSourceFile($file);
            if ($pages < 1) {
                return 'Die PDF-Datei enthält keine Seiten.';
            }
            if ($pages > self::MAX_PAGES) {
                return 'Die PDF-Datei hat zu viele Seiten (höchstens ' . self::MAX_PAGES . ').';
            }
            for ($i = 1; $i <= $pages; $i++) {
                $mpdf->getTemplateSize($mpdf->importPage($i));
            }
        } catch (\Throwable $e) {
            error_log('FormPdfAttachmentService: rejected upload: ' . $e->getMessage());
            return 'Diese PDF-Datei kann nicht eingebunden werden (verschlüsselt, beschädigt oder mit einer Komprimierung, die nicht unterstützt wird). '
                . 'Bitte die Datei neu als PDF speichern, zum Beispiel über „Drucken → Als PDF speichern“, und erneut hochladen.';
        }
        return null;
    }

    private function file(int $tenantId, string $formKey): ?string
    {
        return Identifiers::isValidFormKey($formKey) ? $this->dir($tenantId, $formKey) . '/attachment.pdf' : null;
    }

    private function metaFile(int $tenantId, string $formKey): string
    {
        return $this->dir($tenantId, $formKey) . '/attachment.json';
    }

    private function dir(int $tenantId, string $formKey): string
    {
        return $this->uploadsBase . '/tenant-' . $tenantId . '/forms/' . $formKey;
    }
}
