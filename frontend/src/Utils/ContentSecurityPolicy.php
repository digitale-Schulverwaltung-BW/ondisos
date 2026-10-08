<?php
// frontend/src/Utils/ContentSecurityPolicy.php

declare(strict_types=1);

namespace Frontend\Utils;

/**
 * Content-Security-Policy der öffentlichen Formularseite (Standalone-Frontend).
 *
 * Skripte nur von der eigenen Herkunft oder mit der Nonce der Anfrage: Eingeschleustes HTML (etwa aus einer
 * Survey) führt damit kein JavaScript aus. Styles bleiben mit 'unsafe-inline' erlaubt (SurveyJS setzt Inline-Styles).
 * Nicht gesetzt ist frame-ancestors: Schulen dürfen das Formular per iframe einbinden.
 *
 * Betrieb: CSP_MODE=enforce (Standard) | report-only (nur melden, zum Diagnostizieren) | off.
 * Das WordPress-Plugin sendet keine CSP: Die Seite gehört WordPress (Theme, Sicherheits-Plugins).
 */
final class ContentSecurityPolicy
{
    private static ?string $nonce = null;

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
            // Bilder aus Surveys und Themes dürfen von https-Adressen kommen
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }

    public static function headerName(string $mode): ?string
    {
        return match (strtolower($mode)) {
            'off' => null,
            'report-only' => 'Content-Security-Policy-Report-Only',
            default => 'Content-Security-Policy',
        };
    }

    public static function send(?string $mode = null): void
    {
        if (headers_sent()) {
            return;
        }
        $name = self::headerName($mode ?? (string)(getenv('CSP_MODE') ?: 'enforce'));
        if ($name === null) {
            return;
        }
        header($name . ': ' . self::policy(self::nonce()));
    }
}
