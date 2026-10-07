<?php
// src/Config/Version.php
// Version of the Ondisos release this code belongs to

declare(strict_types=1);

namespace App\Config;

/**
 * Release number of Ondisos. Must equal the WordPress plugin version (`wordpress-plugin/ondisos.php`),
 * the `Stable tag` in `readme.txt` and the badge in the README; a unit test checks this, because the release
 * pipeline refuses a tag that does not match the plugin version.
 */
final class Version
{
    public const CURRENT = '3.1.1';
}
