<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Anmeldung;
use App\Utils\DataFormatter;

/**
 * PDF Template Renderer
 *
 * Handles template loading and rendering for PDF generation.
 * Uses PHP's native templating (extract + include).
 */
class PdfTemplateRenderer
{
    private string $templatePath;
    private const MAX_LOGO_WIDTH = 150; // pixels

    public function __construct(?string $templatePath = null)
    {
        $this->templatePath = $templatePath ?? __DIR__ . '/../../templates/pdf';

        if (!is_dir($this->templatePath)) {
            throw new \RuntimeException(
                "PDF template directory not found: {$this->templatePath}"
            );
        }
    }

    /**
     * Render complete PDF HTML
     *
     * @param Anmeldung $anmeldung The anmeldung data
     * @param array $pdfConfig PDF configuration from forms-config
     * @return string Rendered HTML
     */
    public function render(Anmeldung $anmeldung, array $pdfConfig): string
    {
        // Prepare data
        $rawData = $anmeldung->data ?? [];
        $filteredData = DataFormatter::prepareForPdf($rawData, $pdfConfig);

        // Load logo as base64
        $logoBase64 = $this->loadLogoBase64($pdfConfig['logo'] ?? null);

        // Load CSS
        $styles = $this->loadStyles();

        // Prepare template variables
        $variables = [
            'anmeldung' => $anmeldung,
            'config' => $pdfConfig,
            'data' => $filteredData,
            'rawData' => $rawData,
            'logoBase64' => $logoBase64,
            'styles' => $styles,
            'formatter' => DataFormatter::class, // For use in templates
        ];

        // Render main template
        return $this->renderTemplate('base.php', $variables);
    }

    /**
     * Render a template file with given variables
     *
     * @param string $template Template filename (relative to templates/pdf/)
     * @param array $variables Variables to extract into template scope
     * @return string Rendered HTML
     */
    private function renderTemplate(string $template, array $variables): string
    {
        $templateFile = $this->templatePath . '/' . $template;

        if (!file_exists($templateFile)) {
            throw new \RuntimeException("Template not found: {$template}");
        }

        // Extract variables into local scope
        extract($variables, EXTR_SKIP);

        // Capture output
        ob_start();
        try {
            include $templateFile;
            return ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw new \RuntimeException(
                "Error rendering template {$template}: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Load logo as base64 data URI
     *
     * Optimizes logo by resizing and converting to JPEG if needed.
     *
     * @param string|null $logoPath Path to logo file (relative to backend root)
     * @return string|null Base64 data URI or null if no logo
     */
    private function loadLogoBase64(?string $logoPath): ?string
    {
        if (!$logoPath) {
            return null;
        }

        // Resolve path relative to backend root
        $fullPath = __DIR__ . '/../../' . $logoPath;

        if (!file_exists($fullPath)) {
            error_log("PDF logo not found: {$fullPath}");
            return null;
        }

        try {
            // Load image
            $imageData = file_get_contents($fullPath);
            if ($imageData === false) {
                return null;
            }

            $img = @imagecreatefromstring($imageData);
            if ($img === false) {
                error_log("Failed to load logo image: {$fullPath}");
                return null;
            }

            // Get original dimensions
            $originalWidth = imagesx($img);
            $originalHeight = imagesy($img);

            // Calculate new dimensions (preserve aspect ratio)
            if ($originalWidth > self::MAX_LOGO_WIDTH) {
                $newWidth = self::MAX_LOGO_WIDTH;
                $newHeight = (int)($originalHeight * (self::MAX_LOGO_WIDTH / $originalWidth));

                // Create resized image
                $resized = imagecreatetruecolor($newWidth, $newHeight);

                // Preserve transparency for PNG
                imagealphablending($resized, false);
                imagesavealpha($resized, true);

                imagecopyresampled(
                    $resized,
                    $img,
                    0,
                    0,
                    0,
                    0,
                    $newWidth,
                    $newHeight,
                    $originalWidth,
                    $originalHeight
                );

                imagedestroy($img);
                $img = $resized;
            }

            // Convert to base64 JPEG (smaller file size)
            ob_start();
            imagejpeg($img, null, 85); // 85% quality
            $imageData = ob_get_clean();
            imagedestroy($img);

            return 'data:image/jpeg;base64,' . base64_encode($imageData);

        } catch (\Throwable $e) {
            error_log("Error processing logo: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Load CSS styles for PDF
     *
     * @return string CSS content
     */
    private function loadStyles(): string
    {
        $cssFile = $this->templatePath . '/styles.css';

        if (!file_exists($cssFile)) {
            error_log("PDF styles.css not found, using empty styles");
            return '';
        }

        return file_get_contents($cssFile);
    }

    /**
     * Get the template path
     *
     * @return string Template directory path
     */
    public function getTemplatePath(): string
    {
        return $this->templatePath;
    }
}
