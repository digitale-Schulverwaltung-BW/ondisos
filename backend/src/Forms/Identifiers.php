<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * Syntax rules for the names admins can choose. These names end up in URLs, shortcodes and
 * (for the file fallback) in file paths, so they are restricted to a safe character set.
 */
final class Identifiers
{
    /** Form key, e.g. "bs", "prefill_demo" (URL parameter ?form=…, shortcode form="…"). */
    public static function isValidFormKey(string $key): bool
    {
        // "D": "$" must not match before a trailing newline.
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/D', $key) === 1;
    }

    /** Survey or theme resource name, e.g. "bs.json", "survey_theme.json". */
    public static function isValidResourceName(string $name): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}\.json$/D', $name) === 1;
    }
}
