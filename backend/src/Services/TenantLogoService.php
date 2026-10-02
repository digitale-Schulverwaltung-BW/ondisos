<?php
declare(strict_types=1);

namespace App\Services;

/**
 * The PDF logo of one school (tenant): one image per tenant, uploaded by the tenant admin.
 *
 * Stored as uploads/tenant-<id>/branding/logo.png|jpg. The upload is never kept as sent: it is decoded and
 * re-encoded with GD (drops metadata and anything that is not pixels) and scaled down to MAX_WIDTH.
 * Only PNG and JPEG are accepted; the type is decided by the content, not by name or the browser's claim.
 */
class TenantLogoService
{
    public const MAX_BYTES  = 2 * 1024 * 1024;
    public const MAX_PIXELS = 16_000_000; // checked before decoding (decompression bombs)
    public const MAX_WIDTH  = 600;

    private const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg'];

    /** JPEG needs GD built with libjpeg; without it only PNG can be processed. */
    private static function jpegSupported(): bool
    {
        return function_exists('imagejpeg') && function_exists('imagecreatefromjpeg');
    }

    private readonly string $uploadsBase;

    public function __construct(?string $uploadsBase = null)
    {
        $this->uploadsBase = $uploadsBase ?? __DIR__ . '/../../uploads';
    }

    /** Absolute path of the tenant's logo, or null if it has none. */
    public function path(int $tenantId): ?string
    {
        foreach (self::TYPES as $ext) {
            $file = $this->dir($tenantId) . '/logo.' . $ext;
            if (is_file($file)) {
                return $file;
            }
        }
        return null;
    }

    /** @return array{mime:string,bytes:int,width:int,height:int}|null */
    public function info(int $tenantId): ?array
    {
        $file = $this->path($tenantId);
        $size = $file !== null ? @getimagesize($file) : false;
        if ($file === null || $size === false) {
            return null;
        }
        return ['mime' => $size['mime'], 'bytes' => (int)filesize($file), 'width' => $size[0], 'height' => $size[1]];
    }

    /**
     * Validate and store an uploaded image.
     *
     * @param array<string,mixed> $upload one entry of $_FILES
     * @return string|null error message for the admin, null on success
     */
    public function saveUpload(int $tenantId, array $upload): ?string
    {
        $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return 'Bitte eine Datei auswählen.';
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return 'Die Datei ist zu groß (höchstens 2 MB).';
        }
        $tmp = $upload['tmp_name'] ?? '';
        if ($error !== UPLOAD_ERR_OK || !is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) {
            return 'Der Upload ist fehlgeschlagen. Bitte erneut versuchen.';
        }

        return $this->saveFile($tenantId, $tmp);
    }

    /** Same as saveUpload() for a file already on disk (also used by tests). */
    public function saveFile(int $tenantId, string $file): ?string
    {
        $bytes = @filesize($file);
        if ($bytes === false || $bytes === 0) {
            return 'Die Datei ist leer.';
        }
        if ($bytes > self::MAX_BYTES) {
            return 'Die Datei ist zu groß (höchstens 2 MB).';
        }

        $size = @getimagesize($file);
        $mime = $size !== false ? $size['mime'] : '';
        if (!isset(self::TYPES[$mime])) {
            return 'Nur PNG- oder JPEG-Bilder sind erlaubt.';
        }
        if ($mime === 'image/jpeg' && !self::jpegSupported()) {
            return 'Dieser Server kann keine JPEG-Bilder verarbeiten. Bitte ein PNG hochladen.';
        }
        [$width, $height] = $size;
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            return 'Das Bild ist zu groß (höchstens 16 Megapixel).';
        }

        $data = @file_get_contents($file);
        $img  = $data !== false ? @imagecreatefromstring($data) : false;
        if ($img === false) {
            return 'Das Bild konnte nicht gelesen werden.';
        }

        if ($width > self::MAX_WIDTH) {
            $newHeight = max(1, (int)round($height * self::MAX_WIDTH / $width));
            $scaled    = imagecreatetruecolor(self::MAX_WIDTH, $newHeight);
            if ($mime === 'image/png') {
                imagealphablending($scaled, false);
                imagesavealpha($scaled, true);
            } else {
                imagefill($scaled, 0, 0, (int)imagecolorallocate($scaled, 255, 255, 255));
            }
            imagecopyresampled($scaled, $img, 0, 0, 0, 0, self::MAX_WIDTH, $newHeight, $width, $height);
            imagedestroy($img);
            $img = $scaled;
        }

        ob_start();
        if ($mime === 'image/png') {
            imagesavealpha($img, true);
            imagepng($img, null, 6);
        } else {
            imagejpeg($img, null, 90);
        }
        $out = (string)ob_get_clean();
        imagedestroy($img);

        $dir = $this->dir($tenantId);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('TenantLogoService: cannot create ' . $dir);
            return 'Das Logo konnte nicht gespeichert werden.';
        }

        $target = $dir . '/logo.' . self::TYPES[$mime];
        $tmp    = $target . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $out) === false || !@rename($tmp, $target)) {
            @unlink($tmp);
            error_log('TenantLogoService: cannot write ' . $target);
            return 'Das Logo konnte nicht gespeichert werden.';
        }

        // Only one logo: remove the other format
        foreach (self::TYPES as $ext) {
            $other = $dir . '/logo.' . $ext;
            if ($other !== $target && is_file($other)) {
                @unlink($other);
            }
        }
        return null;
    }

    public function delete(int $tenantId): void
    {
        foreach (self::TYPES as $ext) {
            $file = $this->dir($tenantId) . '/logo.' . $ext;
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function dir(int $tenantId): string
    {
        return $this->uploadsBase . '/tenant-' . $tenantId . '/branding';
    }
}
