<?php
// src/Services/ReplayGuard.php

declare(strict_types=1);

namespace App\Services;

/**
 * Merkt sich einmalig verwendbare Kennungen (Nonces) für eine begrenzte Zeit.
 *
 * Dateibasiert wie RateLimiter (ein Server); die Datei wird atomar mit Modus "x" angelegt,
 * sodass zwei gleichzeitige Anfragen mit derselben Kennung nicht beide durchkommen.
 */
class ReplayGuard
{
    public function __construct(private string $storageDir, private int $ttlSeconds)
    {
        $this->storageDir = rtrim($storageDir, '/');
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }
    }

    /** @return bool true beim ersten Mal, false bei Wiederholung innerhalb der Gültigkeit */
    public function firstUse(string $key): bool
    {
        $file = $this->storageDir . '/n_' . hash('sha256', $key);

        $handle = @fopen($file, 'x');
        if ($handle === false) {
            // Existiert schon: nur dann eine Wiederholung, wenn der Eintrag noch gilt
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime >= time() - $this->ttlSeconds) {
                return false;
            }
            @unlink($file);
            $handle = @fopen($file, 'x');
            if ($handle === false) {
                return false;
            }
        }
        fclose($handle);

        $this->maybeCleanup();

        return true;
    }

    private function maybeCleanup(): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }
        $cutoff = time() - $this->ttlSeconds;
        foreach (glob($this->storageDir . '/n_*') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($file);
            }
        }
    }
}
