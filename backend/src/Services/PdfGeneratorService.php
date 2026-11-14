<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Anmeldung;
use Mpdf\Mpdf;
use Mpdf\MpdfException;

/**
 * PDF Generator Service
 *
 * Main service for generating PDFs from Anmeldung data.
 * Uses mPDF library for PDF creation.
 */
class PdfGeneratorService
{
    private PdfTemplateRenderer $renderer;

    public function __construct(PdfTemplateRenderer $renderer)
    {
        $this->renderer = $renderer;
    }

    /**
     * Generate PDF and send as download to browser
     *
     * This method sets headers and outputs the PDF directly.
     * Should be called as the last action before script termination.
     *
     * @param Anmeldung $anmeldung The anmeldung data
     * @param array $pdfConfig PDF configuration from forms-config
     * @return void
     * @throws MpdfException If PDF generation fails
     */
    public function generateAndDownload(Anmeldung $anmeldung, array $pdfConfig): void
    {
        $pdf = $this->generate($anmeldung, $pdfConfig);

        // Own headers instead of mPDF's DOWNLOAD output, which sends "Cache-Control: public":
        // the confirmation holds personal data and must not stay in shared or browser caches.
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $this->generateFilename($anmeldung) . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        echo $pdf;
    }

    /**
     * Generate PDF and return as binary string
     *
     * Useful for email attachments or further processing.
     *
     * @param Anmeldung $anmeldung The anmeldung data
     * @param array $pdfConfig PDF configuration from forms-config
     * @param string|null $watermark diagonal text over every page (the editor's preview)
     * @return string PDF binary data
     * @throws MpdfException If PDF generation fails
     */
    public function generate(Anmeldung $anmeldung, array $pdfConfig, ?string $watermark = null): string
    {
        // Create mPDF instance
        $mpdf = $this->createMpdf();

        if ($watermark !== null) {
            $mpdf->SetWatermarkText($watermark, 0.08);
            $mpdf->showWatermarkText = true;
        }

        // Render HTML
        $html = $this->renderer->render($anmeldung, $pdfConfig);

        // Write HTML to PDF
        $mpdf->WriteHTML($html);
        FormPdfAttachmentService::appendTo($mpdf, $this->attachmentPath($pdfConfig));

        // Return as string
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /** @param array<string,mixed> $pdfConfig */
    private function attachmentPath(array $pdfConfig): ?string
    {
        $path = $pdfConfig['attachment_path'] ?? null;
        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * Create and configure mPDF instance
     *
     * @return Mpdf Configured mPDF instance
     * @throws MpdfException If mPDF creation fails
     */
    private function createMpdf(): Mpdf
    {
        return new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 16,
            'margin_bottom' => 16,
            'margin_header' => 9,
            'margin_footer' => 9,
            'default_font' => 'dejavusans', // Unicode-safe font
            'tempDir' => sys_get_temp_dir(), // Use system temp dir
        ]);
    }

    /**
     * Generate filename for PDF download
     *
     * Format: bestaetigung-{formularname}-{id}.pdf
     * Example: bestaetigung-bs-123.pdf
     *
     * @param Anmeldung $anmeldung The anmeldung data
     * @return string Filename
     */
    private function generateFilename(Anmeldung $anmeldung): string
    {
        $formularName = $anmeldung->formular;
        $id = $anmeldung->id;

        // Sanitize formular name (remove special chars)
        $formularName = preg_replace('/[^a-zA-Z0-9]/', '', $formularName);

        return sprintf('bestaetigung-%s-%d.pdf', strtolower($formularName), $id);
    }
}
