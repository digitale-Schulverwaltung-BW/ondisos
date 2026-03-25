<?php
declare(strict_types=1);

namespace App\Utils;

class FilenameSanitizer
{
    /**
     * Sanitize a filename stem (without extension) for safe storage.
     *
     * Replaces German umlauts with ASCII equivalents, converts all
     * remaining non-alphanumeric characters to underscores, and
     * collapses runs of underscores.
     */
    public static function sanitizeStem(string $stem): string
    {
        // Replace German umlauts with ASCII equivalents
        $stem = str_replace(
            ['ä', 'ö', 'ü', 'ß', 'Ä', 'Ö', 'Ü'],
            ['ae', 'oe', 'ue', 'ss', 'Ae', 'Oe', 'Ue'],
            $stem
        );
        // Replace remaining non-safe chars with underscore
        $stem = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $stem);
        // Collapse multiple underscores and trim
        $stem = trim(preg_replace('/_+/', '_', $stem), '_');

        return $stem !== '' ? $stem : 'upload';
    }

    /**
     * Build the on-disk filename for a given original name and anmeldung ID.
     *
     * Returns "{id}_{sanitized_stem}.{ext}".
     */
    public static function diskName(int $anmeldungId, string $originalFilename): string
    {
        $stem = pathinfo($originalFilename, PATHINFO_FILENAME);
        $ext  = pathinfo($originalFilename, PATHINFO_EXTENSION);

        return $anmeldungId . '_' . self::sanitizeStem($stem) . '.' . $ext;
    }
}
