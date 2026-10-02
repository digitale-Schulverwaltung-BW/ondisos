<?php
declare(strict_types=1);

namespace App\Services;

/**
 * The accent colour of one school (tenant) in its PDF confirmations (the bars at the left of the intro and of the
 * custom sections). Stored next to the logo as uploads/tenant-<id>/branding/accent.txt; without a file the PDF keeps DEFAULT.
 *
 * Only "#rrggbb" is ever stored or put into the stylesheet (normalize() also accepts "#rgb" and a missing "#").
 */
class TenantAccentColor
{
    public const DEFAULT = '#3498db';

    private readonly string $uploadsBase;

    public function __construct(?string $uploadsBase = null)
    {
        $this->uploadsBase = $uploadsBase ?? __DIR__ . '/../../uploads';
    }

    /** "#RGB" / "RGB" / "#RRGGBB" / "RRGGBB" (any case, surrounding blanks) → "#rrggbb", anything else → null. */
    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $hex = ltrim(trim($value), '#');
        if (preg_match('/^[0-9a-fA-F]{3}$/D', $hex) === 1) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return preg_match('/^[0-9a-fA-F]{6}$/D', $hex) === 1 ? '#' . strtolower($hex) : null;
    }

    /** The tenant's colour, or null if it has none (or the file is unusable). */
    public function get(int $tenantId): ?string
    {
        $raw = @file_get_contents($this->file($tenantId));
        return $raw === false ? null : self::normalize($raw);
    }

    /** @return string|null error message, null on success */
    public function save(int $tenantId, string $input): ?string
    {
        $color = self::normalize($input);
        if ($color === null) {
            return 'Bitte eine Farbe im Format #RRGGBB angeben (zum Beispiel #3498db).';
        }
        $dir = dirname($this->file($tenantId));
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('TenantAccentColor: cannot create ' . $dir);
            return 'Die Farbe konnte nicht gespeichert werden.';
        }
        $target = $this->file($tenantId);
        $tmp    = $target . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $color) === false || !@rename($tmp, $target)) {
            @unlink($tmp);
            error_log('TenantAccentColor: cannot write ' . $target);
            return 'Die Farbe konnte nicht gespeichert werden.';
        }
        return null;
    }

    public function delete(int $tenantId): void
    {
        $file = $this->file($tenantId);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function file(int $tenantId): string
    {
        return $this->uploadsBase . '/tenant-' . $tenantId . '/branding/accent.txt';
    }
}
