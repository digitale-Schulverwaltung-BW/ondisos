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
    private const MAX_LOGO_WIDTH = 300; // pixels (prevents unnecessary downscaling)

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

        // Load logo with dimensions
        $logoData = $this->loadLogoData($pdfConfig['logo'] ?? null);

        // Load CSS
        $styles = $this->loadStyles();

        // Prepare template variables
        $variables = [
            'anmeldung' => $anmeldung,
            'config' => $pdfConfig,
            'data' => $filteredData,
            'rawData' => $rawData,
            'logoBase64' => $logoData['dataUri'] ?? null, // Keep for backward compat
            'logoData' => $logoData,
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
     * Load logo data with dimensions
     *
     * Optimizes logo by resizing and converting to JPEG if needed.
     *
     * @param string|null $logoPath Path to logo file (absolute or relative to backend root)
     * @return array{dataUri: string|null, width: int|null, height: int|null}
     */
    private function loadLogoData(?string $logoPath): array
    {
        if (!$logoPath) {
            return ['dataUri' => null, 'width' => null, 'height' => null];
        }

        // Resolve path: use as-is if absolute, otherwise relative to templates/pdf/assets/
        if (str_starts_with($logoPath, '/')) {
            // Absolute path - use directly (e.g. Docker: /var/www/html/templates/pdf/assets/logo.png)
            $fullPath = $logoPath;
        } else {
            // Relative path - resolve from the PDF assets directory
            // e.g. 'logo.png' → backend/templates/pdf/assets/logo.png
            $fullPath = __DIR__ . '/../../templates/pdf/assets/' . $logoPath;
        }

        if (!file_exists($fullPath)) {
            error_log("PDF logo not found: {$fullPath}");
            return ['dataUri' => null, 'width' => null, 'height' => null];
        }

        try {
            // Load image
            $imageData = file_get_contents($fullPath);
            if ($imageData === false) {
                return ['dataUri' => null, 'width' => null, 'height' => null];
            }

            $img = @imagecreatefromstring($imageData);
            if ($img === false) {
                error_log("Failed to load logo image: {$fullPath}");
                return ['dataUri' => null, 'width' => null, 'height' => null];
            }

            // Get original dimensions
            $originalWidth = imagesx($img);
            $originalHeight = imagesy($img);

            // Track final dimensions (will be updated if resized)
            $finalWidth = $originalWidth;
            $finalHeight = $originalHeight;

            // Detect if image has transparency by file extension
            // PNG and GIF support transparency - keep them as PNG to preserve it
            $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $hasTransparency = in_array($extension, ['png', 'gif'], true);

            // For PNG/GIF, always preserve as PNG even if currently no transparency is used
            // This is simpler and more reliable than pixel-by-pixel alpha detection

            // Calculate new dimensions (preserve aspect ratio)
            if ($originalWidth > self::MAX_LOGO_WIDTH) {
                $newWidth = self::MAX_LOGO_WIDTH;
                $newHeight = (int)($originalHeight * (self::MAX_LOGO_WIDTH / $originalWidth));

                // Update final dimensions
                $finalWidth = $newWidth;
                $finalHeight = $newHeight;

                // Create resized image
                $resized = imagecreatetruecolor($newWidth, $newHeight);

                // Preserve transparency for PNG, or use white background for JPEG
                if ($hasTransparency) {
                    imagealphablending($resized, false);
                    imagesavealpha($resized, true);
                } else {
                    // Fill with white background for JPEG conversion
                    $white = imagecolorallocate($resized, 255, 255, 255);
                    imagefill($resized, 0, 0, $white);
                }

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
            } else {
                // No resizing needed, but may need to add white background for JPEG
                if (!$hasTransparency) {
                    $withBg = imagecreatetruecolor($originalWidth, $originalHeight);
                    $white = imagecolorallocate($withBg, 255, 255, 255);
                    imagefill($withBg, 0, 0, $white);
                    imagecopy($withBg, $img, 0, 0, 0, 0, $originalWidth, $originalHeight);
                    imagedestroy($img);
                    $img = $withBg;
                }
            }

            // Convert to base64: PNG if transparent, JPEG otherwise (smaller file size)
            ob_start();
            if ($hasTransparency) {
                imagepng($img, null, 6); // PNG with compression level 6
                $mimeType = 'image/png';
            } else {
                imagejpeg($img, null, 85); // JPEG 85% quality
                $mimeType = 'image/jpeg';
            }
            $imageData = ob_get_clean();
            imagedestroy($img);

            $dataUri = "data:{$mimeType};base64," . base64_encode($imageData);

            return [
                'dataUri' => $dataUri,
                'width' => $finalWidth,
                'height' => $finalHeight,
            ];

        } catch (\Throwable $e) {
            error_log("Error processing logo: " . $e->getMessage());
            return ['dataUri' => null, 'width' => null, 'height' => null];
        }
    }

    /**
     * Check if PNG image has alpha channel (transparency)
     *
     * Scans the image to detect if any pixel uses the alpha channel.
     * More reliable than checking a single pixel.
     *
     * @param \GdImage $img GD image resource
     * @return bool True if alpha channel is used
     */
    private function pngHasAlphaChannel($img): bool
    {
        // Check a sample of pixels (not all for performance)
        // If any pixel has alpha != 127 (opaque), we have transparency
        $width = imagesx($img);
        $height = imagesy($img);

        // Sample every 10th pixel for performance
        $step = 10;
        for ($x = 0; $x < $width; $x += $step) {
            for ($y = 0; $y < $height; $y += $step) {
                $rgba = imagecolorat($img, $x, $y);
                $alpha = ($rgba & 0x7F000000) >> 24;

                // Alpha channel: 0 = opaque, 127 = transparent
                // If we find any transparency, return true
                if ($alpha > 0) {
                    return true;
                }
            }
        }

        return false;
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
