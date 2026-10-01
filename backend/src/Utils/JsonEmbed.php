<?php
declare(strict_types=1);

namespace App\Utils;

/**
 * Makes JSON text safe to place inside an HTML <script> element: "</script>", "<!--" and friends are written as
 * < etc., so nothing in the JSON can end the element. Same behaviour as Frontend\Utils\JsonEmbed (the frontend
 * is deployed separately and cannot share the class). Objects stay objects ("{}" does not become "[]").
 */
final class JsonEmbed
{
    /**
     * @throws \JsonException if $json is not valid JSON
     */
    public static function encode(string $json): string
    {
        $value = json_decode($json, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);

        return json_encode(
            $value,
            JSON_THROW_ON_ERROR
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }
}
