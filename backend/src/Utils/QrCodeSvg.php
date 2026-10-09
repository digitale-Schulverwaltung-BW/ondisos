<?php
// src/Utils/QrCodeSvg.php
// QR code as inline SVG (no GD or Imagick needed)

declare(strict_types=1);

namespace App\Utils;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

final class QrCodeSvg
{
    /** SVG markup without XML prolog, ready to embed in HTML. */
    public static function render(string $content, int $size = 220): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd())))->writeString($content);

        return trim((string)preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg));
    }
}
