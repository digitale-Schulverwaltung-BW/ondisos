<?php
// frontend/src/Utils/AssetVersion.php

declare(strict_types=1);

namespace Frontend\Utils;

/**
 * Cache-busting value for the ?v= parameter of a CSS or JS file.
 *
 * The release number alone is not enough: files can change without a new number (a SurveyJS update
 * during a release, a copy deployed by hand), and a phone then keeps its cached copy under the same
 * address. A file served without Cache-Control is kept by the browser on its own estimate. An old
 * cached SurveyJS script next to a new stylesheet breaks the layout (e.g. the signature pad's clear
 * button filling half the pad). The file's modification time changes with every new copy.
 */
final class AssetVersion
{
    /**
     * @param string $file    Path of the file on disk
     * @param string $release Release number placed in front (empty: modification time only)
     * @return string e.g. "3.2.0-1731322908"; just $release (or "0") if the file cannot be read
     */
    public static function of(string $file, string $release = ''): string
    {
        $mtime = is_file($file) ? @filemtime($file) : false;
        if ($mtime === false) {
            return $release !== '' ? $release : '0';
        }

        return $release !== '' ? $release . '-' . $mtime : (string)$mtime;
    }
}
