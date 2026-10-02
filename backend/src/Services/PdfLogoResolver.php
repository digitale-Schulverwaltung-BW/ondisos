<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Decides which logo a PDF gets. Shared by the token download, the admin download and the PDF preview.
 *
 * Order: "logo": false in the form config (no logo, explicitly) → a path set by the platform admin in the config →
 * the logo the school uploaded → PDF_LOGO_<FORMKEY> → PDF_LOGO_PATH from the environment.
 * The result always carries 'logo' as a path string or null.
 *
 * The school's accent colour travels the same way: 'accent_color' is "#rrggbb" or null (= the default of the template).
 * It is taken from the tenant only; a value in the form config is ignored.
 */
final class PdfLogoResolver
{
    public function __construct(
        private readonly TenantLogoService $tenantLogos = new TenantLogoService(),
        private readonly TenantAccentColor $accentColors = new TenantAccentColor(),
    ) {
    }

    /**
     * @param array<string,mixed> $pdfConfig
     * @return array<string,mixed> $pdfConfig with 'logo' resolved
     */
    public function resolve(array $pdfConfig, string $formKey, ?int $tenantId): array
    {
        $logo = $pdfConfig['logo'] ?? null;
        $pdfConfig['accent_color'] = $tenantId !== null ? $this->accentColors->get($tenantId) : null;

        if ($logo === false) {
            $pdfConfig['logo'] = null;
            return $pdfConfig;
        }
        if (is_string($logo) && $logo !== '') {
            return $pdfConfig;
        }

        $path = $tenantId !== null ? $this->tenantLogos->path($tenantId) : null;
        if ($path === null) {
            $env  = getenv('PDF_LOGO_' . strtoupper($formKey));
            $path = ($env !== false && $env !== '') ? $env : null;
        }
        if ($path === null) {
            $env  = getenv('PDF_LOGO_PATH');
            $path = ($env !== false && $env !== '') ? $env : null;
        }

        $pdfConfig['logo'] = $path;
        return $pdfConfig;
    }
}
