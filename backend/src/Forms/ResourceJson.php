<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * Reads the JSON files that ship with the code (backend/resources/survey-templates/): building blocks, templates,
 * examples. A missing or broken file is a defect of the installation, not user input: it throws.
 */
final class ResourceJson
{
    /** Manifest entries name files like "blocks/person.json": lower case, no way out of the directory. */
    public static function isSafeFile(mixed $file): bool
    {
        return is_string($file) && preg_match('#^[a-z0-9_/-]+\.json$#', $file) === 1 && !str_contains($file, '..');
    }

    /**
     * @return array<string,mixed> the decoded JSON object
     * @throws \RuntimeException
     */
    public static function readObject(string $file): array
    {
        $data = json_decode(self::readText($file), true, 64);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new \RuntimeException(basename($file) . ': kein JSON-Objekt');
        }
        return $data;
    }

    /** @throws \RuntimeException */
    public static function readText(string $file): string
    {
        $text = is_file($file) ? file_get_contents($file) : false;
        if ($text === false) {
            throw new \RuntimeException('Datei fehlt: ' . basename($file));
        }
        try {
            json_decode($text, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(basename($file) . ': kein gültiges JSON (' . $e->getMessage() . ')');
        }
        return $text;
    }
}
