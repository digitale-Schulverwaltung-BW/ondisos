<?php
// src/Services/SchoolLookupService.php

declare(strict_types=1);

namespace App\Services;

use App\Config\EnvLoader;

/**
 * Fuzzy lookup service: maps free-text school names to Dienststellenschlüssel.
 *
 * CSV format (tab-separated, UTF-8, with header row):
 *   "Schul-/Dienststellennummer"  "Schulbezeichnung (intern)"  "Anschrift Ort"
 *
 * Matching strategy:
 *   1. Split query on first comma → name part + city part
 *   2. For each CSV entry compute composite score:
 *      - 60% similar_text + 40% levenshtein-based score on normalized strings
 *      - If city available: 75% name score + 25% city score
 *   3. Return best match above $threshold, else null
 */
class SchoolLookupService
{
    private readonly string $csvPath;
    private readonly float $threshold;

    /** @var array<array{id: string, name: string, city: string}> */
    private array $entries = [];

    private bool $loaded = false;
    private bool $available;

    public function __construct(?string $csvPath = null, float $threshold = 0.70)
    {
        $this->csvPath = $csvPath ?? (string)EnvLoader::get(
            'SCHOOL_LOOKUP_CSV',
            dirname(__DIR__, 2) . '/data/schulen.csv'
        );
        $this->threshold = $threshold;
        $this->available = file_exists($this->csvPath);
    }

    /**
     * Returns true if the CSV file exists and the feature is operational.
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Find the best matching Dienststellenschlüssel for a free-text school name.
     *
     * @return array{schluessel: string, confidence: float}|null  null if no match ≥ threshold
     */
    public function findBestMatch(string $query): ?array
    {
        if (!$this->available) {
            return null;
        }

        $this->loadCsv();

        if (empty($this->entries)) {
            return null;
        }

        // Split "Schulname, Ort" into name and city parts
        $commaPos = strpos($query, ',');
        if ($commaPos !== false) {
            $queryName = substr($query, 0, $commaPos);
            $queryCity = substr($query, $commaPos + 1);
        } else {
            $queryName = $query;
            $queryCity = '';
        }

        $normQueryName = $this->normalize($queryName);
        $normQueryCity = $this->normalize($queryCity);
        $hasCity = $normQueryCity !== '';

        $bestScore = 0.0;
        $bestEntry = null;

        foreach ($this->entries as $entry) {
            $nameScore = $this->similarity($normQueryName, $this->normalize($entry['name']));

            if ($hasCity && $entry['city'] !== '') {
                $cityScore = $this->similarity($normQueryCity, $this->normalize($entry['city']));
                $composite = 0.75 * $nameScore + 0.25 * $cityScore;
            } else {
                $composite = $nameScore;
            }

            if ($composite > $bestScore) {
                $bestScore = $composite;
                $bestEntry = $entry;
            }
        }

        if ($bestEntry === null || $bestScore < $this->threshold) {
            return null;
        }

        return ['schluessel' => $bestEntry['id'], 'confidence' => $bestScore];
    }

    /**
     * Load and parse the CSV file (lazy, called at most once).
     */
    private function loadCsv(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        $handle = @fopen($this->csvPath, 'r');
        if ($handle === false) {
            return;
        }

        // Auto-detect delimiter from first line (tab, semicolon, or comma)
        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            return;
        }
        $sep = $this->detectSeparator($firstLine);
        rewind($handle);

        // Skip header row
        fgetcsv($handle, 0, $sep);

        while (($row = fgetcsv($handle, 0, $sep)) !== false) {
            if (count($row) < 2) {
                continue;
            }

            $this->entries[] = [
                'id'   => trim($row[0], " \t\n\r\0\x0B\""),
                'name' => trim($row[1], " \t\n\r\0\x0B\""),
                'city' => isset($row[2]) ? trim($row[2], " \t\n\r\0\x0B\"") : '',
            ];
        }

        fclose($handle);
    }

    /**
     * Detect the field separator from a sample line.
     * Counts occurrences of tab, semicolon, and comma and returns the most frequent one.
     * Falls back to tab if all counts are equal.
     */
    private function detectSeparator(string $line): string
    {
        $counts = [
            "\t" => substr_count($line, "\t"),
            ';'  => substr_count($line, ';'),
            ','  => substr_count($line, ','),
        ];

        arsort($counts);
        $winner = array_key_first($counts);

        return $counts[$winner] > 0 ? $winner : "\t";
    }

    /**
     * Normalize a string for comparison:
     * UTF-8 umlauts → ASCII, lowercase, only alphanumeric + space, collapsed whitespace.
     */
    private function normalize(string $input): string
    {
        // Transliterate umlauts: ä→a, ö→o, ü→u, ß→ss, etc.
        $result = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $input);
        if ($result === false) {
            $result = $input;
        }

        $result = strtolower($result);
        $result = (string)preg_replace('/[^a-z0-9 ]/', ' ', $result);
        $result = (string)preg_replace('/\s+/', ' ', $result);

        return trim($result);
    }

    /**
     * Compute similarity score between two already-normalized strings.
     * Returns a value between 0.0 (no similarity) and 1.0 (identical).
     *
     * Weighted combination:
     *   60% similar_text percentage (handles token reordering)
     *   40% levenshtein-based score (penalizes character edits strictly)
     */
    private function similarity(string $a, string $b): float
    {
        if ($a === '' && $b === '') {
            return 1.0;
        }

        if ($a === '' || $b === '') {
            return 0.0;
        }

        // similar_text — percentage of common characters
        similar_text($a, $b, $percent);
        $stScore = $percent / 100.0;

        // levenshtein — PHP built-in is limited to 255 chars; fall back to similar_text only for longer strings
        $maxLen = max(strlen($a), strlen($b));
        if ($maxLen <= 255) {
            $dist = levenshtein($a, $b);
            $lvScore = 1.0 - ($dist / $maxLen);
        } else {
            // Strings too long for levenshtein: use similar_text score only
            return $stScore;
        }

        return 0.6 * $stScore + 0.4 * $lvScore;
    }
}
