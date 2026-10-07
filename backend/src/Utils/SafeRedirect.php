<?php
declare(strict_types=1);

namespace App\Utils;

/**
 * Restricts user-supplied redirect targets (e.g. a return_url POST field) to pages of this admin interface,
 * so a forged request cannot send the browser to a foreign site (open redirect).
 */
final class SafeRedirect
{
    /**
     * Only a bare script name next to the current page, optionally with a query string (e.g. "detail.php?id=5").
     * No scheme, host, leading slash or backslash, path segments, control characters or fragments.
     */
    public static function local(?string $url, string $default = 'index.php'): string
    {
        if ($url === null || !preg_match('/^[A-Za-z0-9_-]+\.php(\?[^\x00-\x20\x7f\\\\#]*)?$/', $url)) {
            return $default;
        }

        return $url;
    }
}
