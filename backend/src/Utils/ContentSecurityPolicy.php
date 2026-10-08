<?php
// src/Utils/ContentSecurityPolicy.php

declare(strict_types=1);

namespace App\Utils;

/**
 * Content-Security-Policy der Verwaltungsoberfläche.
 *
 * Skripte nur von der eigenen Herkunft oder mit der Nonce der Anfrage: Eingeschleustes HTML (etwa aus einem
 * Anmeldefeld oder einer Survey) führt damit kein JavaScript aus, auch wenn eine Ausgabe einmal nicht escaped wäre.
 * Dafür gibt es in den Seiten keine Inline-Handler (onclick=…); Bestätigungen und Ähnliches laufen über
 * data-Attribute und assets/js/admin-ui.js. Inline-Skripte tragen die Nonce.
 *
 * Styles: 'unsafe-inline' bleibt (style-Attribute und kleine <style>-Blöcke); das Risiko ist dort deutlich geringer.
 *
 * Die Vorschau (form_preview_frame.php) setzt ihre eigene, strengere Richtlinie und ersetzt diesen Header.
 *
 * Betrieb: CSP_MODE=enforce (Standard) | report-only (nur melden, zum Diagnostizieren) | off.
 */
final class ContentSecurityPolicy
{
    private static ?string $nonce = null;

    /** Nonce dieser Anfrage (für <script nonce="…">). */
    public static function nonce(): string
    {
        return self::$nonce ??= rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    public static function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }

    /** Header-Name zum Modus, null bei "off". */
    public static function headerName(string $mode): ?string
    {
        return match (strtolower($mode)) {
            'off' => null,
            'report-only' => 'Content-Security-Policy-Report-Only',
            default => 'Content-Security-Policy',
        };
    }

    /** Sendet den Header (einmal pro Anfrage, vor jeder Ausgabe). */
    public static function send(?string $mode = null): void
    {
        if (headers_sent()) {
            return;
        }
        $name = self::headerName($mode ?? (string)($_ENV['CSP_MODE'] ?? 'enforce'));
        if ($name === null) {
            return;
        }
        header($name . ': ' . self::policy(self::nonce()));
    }
}
