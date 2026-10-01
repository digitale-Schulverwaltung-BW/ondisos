<?php
// frontend/src/Utils/JsonEmbed.php

declare(strict_types=1);

namespace Frontend\Utils;

/**
 * Makes JSON text safe to place inside an HTML <script> element (as JavaScript or as
 * type="application/json" data).
 *
 * Survey and theme JSON are written by admins in the backend. Printed as they are, a "</script>" or
 * "<!--" inside a string would end the script element and let the rest run as page markup. The text is
 * therefore decoded and encoded again with every HTML-significant character escaped (<, &, …);
 * the result means exactly the same to JSON.parse and JavaScript, but cannot break out of the element.
 *
 * Objects stay objects: decoding as arrays would turn "{}" into "[]".
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
