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
        // Generate filename
        $filename = $this->generateFilename($anmeldung);

        // Create mPDF instance
        $mpdf = $this->createMpdf();

        // Render HTML
        $html = $this->renderer->render($anmeldung, $pdfConfig);

        // Write HTML to PDF
        $mpdf->WriteHTML($html);

        // Output as download
        $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
    }

    /**
     * Generate PDF and return as binary string
     *
     * Useful for email attachments or further processing.
     *
     * @param Anmeldung $anmeldung The anmeldung data
     * @param array $pdfConfig PDF configuration from forms-config
     * @return string PDF binary data
     * @throws MpdfException If PDF generation fails
     */
    public function generate(Anmeldung $anmeldung, array $pdfConfig): string
    {
        // Create mPDF instance
        $mpdf = $this->createMpdf();

        // Render HTML
        $html = $this->renderer->render($anmeldung, $pdfConfig);

        // Write HTML to PDF
        $mpdf->WriteHTML($html);

        // Return as string
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
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
