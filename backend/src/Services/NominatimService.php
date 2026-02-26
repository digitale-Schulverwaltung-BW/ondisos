<?php
// src/Services/NominatimService.php

declare(strict_types=1);

namespace App\Services;

use App\Config\EnvLoader;

class NominatimService
{
    private readonly bool $enabled;
    private readonly string $contact;
    private array $cache = [];
    private float $lastRequestTime = 0.0;
    private const RATE_LIMIT_SECONDS = 1.1; // Nominatim ToS: max 1 req/s

    public function __construct()
    {
        $contact = (string)EnvLoader::get('NOMINATIM_CONTACT', '');
        $this->contact = $contact;
        $this->enabled = $contact !== '';
    }

    /**
     * Ermittelt den Ortsteil (Suburb) für eine gegebene Adresse via OpenStreetMap Nominatim.
     * Gibt leeren String zurück wenn NOMINATIM_CONTACT nicht in .env gesetzt ist.
     * Ergebnisse werden pro Export-Lauf in-memory gecacht.
     *
     * @param string $hausnr   Hausnummer (z.B. "12a")
     * @param string $strasse  Straßenname (z.B. "Musterstraße")
     * @param string $plz      Postleitzahl (z.B. "76133")
     * @param string $ort      Ort (z.B. "Karlsruhe")
     * @return string Ortsteil (z.B. "Mühlburg") oder leerer String wenn nicht ermittelbar
     */
    public function getSuburb(string $hausnr, string $strasse, string $plz, string $ort): string
    {
        if (!$this->enabled) {
            return '';
        }

        $cacheKey = "{$plz}|{$ort}|{$strasse}|{$hausnr}";

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $this->enforceRateLimit();

        $params = http_build_query([
            'street'         => trim("{$hausnr} {$strasse}"),
            'city'           => $ort,
            'postalcode'     => $plz,
            'addressdetails' => '1',
            'format'         => 'json',
            'limit'          => '1',
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://nominatim.openstreetmap.org/search?' . $params,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => 'ondisos/2.6 (' . $this->contact . ' - https://github.com/digitale-Schulverwaltung-BW/ondisos)',
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $suburb = '';

        if ($response !== false) {
            $data = json_decode($response, true);
            $suburb = (string)($data[0]['address']['suburb']
                ?? $data[0]['address']['quarter']
                ?? '');
        }

        $this->cache[$cacheKey] = $suburb;
        return $suburb;
    }

    private function enforceRateLimit(): void
    {
        $now = microtime(true);
        $elapsed = $now - $this->lastRequestTime;

        if ($elapsed < self::RATE_LIMIT_SECONDS) {
            usleep((int)(self::RATE_LIMIT_SECONDS - $elapsed) * 1_000_000);
        }

        $this->lastRequestTime = microtime(true);
    }
}
