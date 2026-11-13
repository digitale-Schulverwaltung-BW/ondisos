<?php
// src/Utils/AssetVersion.php

declare(strict_types=1);

namespace App\Utils;

/**
 * Asset-URLs mit Änderungszeit (`?v=<filemtime>`), damit Browser nach einem Update die neue Fassung laden.
 *
 * Für ES-Module reicht `?v=` am Einstiegsskript nicht: `import './ui.js'` löst ohne Query auf und kommt aus dem Cache.
 * Deshalb liefert importMapTag() eine Import-Map, die jedes Modul der genannten Verzeichnisse auf seine versionierte
 * URL abbildet. Schlüssel und Werte sind relativ zur Seite und beginnen mit `./` (sonst hielte der Browser den Schlüssel
 * für einen Paketnamen und nicht für eine URL).
 */
final class AssetVersion
{
    public function __construct(private readonly string $publicDir)
    {
    }

    public static function forPublic(): self
    {
        return new self(dirname(__DIR__, 2) . '/public');
    }

    /** `assets/x.js` → `assets/x.js?v=1731400000`; eine fehlende Datei bleibt ohne Version. */
    public function url(string $path): string
    {
        $mtime = @filemtime($this->publicDir . '/' . ltrim($path, '/'));
        return $mtime === false ? $path : $path . '?v=' . $mtime;
    }

    /**
     * Abbildung Modul → versionierte URL für alle .js-Dateien der Verzeichnisse (nicht rekursiv), sortiert.
     *
     * @param list<string> $dirs Verzeichnisse relativ zu public/, z. B. 'assets/survey-builder'
     * @return array<string, string>
     */
    public function importMap(array $dirs): array
    {
        $map = [];
        foreach ($dirs as $dir) {
            $dir = trim($dir, '/');
            foreach (glob($this->publicDir . '/' . $dir . '/*.js') ?: [] as $file) {
                $path = $dir . '/' . basename($file);
                // "./": without it, an import map takes the key for a package name, not for a URL
                $map['./' . $path] = './' . $this->url($path);
            }
        }
        ksort($map);
        return $map;
    }

    /** `<script type="importmap">` mit der Nonce der Content-Security-Policy; vor dem ersten Modul-Skript ausgeben. */
    public function importMapTag(array $dirs, string $nonce): string
    {
        $json = json_encode(['imports' => (object)$this->importMap($dirs)], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
        return '<script type="importmap" nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '">' . $json . '</script>';
    }
}
