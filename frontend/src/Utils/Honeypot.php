<?php
// frontend/src/Utils/Honeypot.php

declare(strict_types=1);

namespace Frontend\Utils;

/**
 * Honeypot gegen automatisches Ausfüllen: Die Survey-Handler legen ein für Menschen unsichtbares
 * Feld an. Bots, die alle Felder der Seite befüllen, füllen es mit; echte Besucher nie.
 *
 * Bei einem Treffer wird die Anmeldung nicht weitergegeben, der Absender erhält aber eine
 * Erfolgsantwort (der Bot soll nicht lernen, was ihn verraten hat).
 */
final class Honeypot
{
    /** Name des Formularfeldes (klingt nach einem echten Feld, ist aber leer zu lassen). */
    public const FIELD = 'website_url';

    /** @param array<string,mixed> $post */
    public static function isTriggered(array $post): bool
    {
        $value = $post[self::FIELD] ?? '';

        return is_string($value) ? trim($value) !== '' : $value !== null && $value !== [];
    }

    /** @return array{success: bool} */
    public static function fakeSuccess(): array
    {
        return ['success' => true];
    }
}
