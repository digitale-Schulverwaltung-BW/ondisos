<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * Building blocks of the graphical survey builder (PLAN-3.2.0, AP6): groups of standard fields (consent, person, address,
 * guardians, signature …) that the builder inserts as one panel or question.
 *
 * They are files next to the code (backend/resources/survey-templates/), the same for every tenant: manifest.json lists
 * them, each block is one SurveyJS element. A block with "param" exists once per value: the placeholder in its strings
 * (e.g. "%N%" in "Erz%N%Name") is replaced, so "erziehungsberechtigte" becomes "erziehungsberechtigte_1" and "…_2".
 *
 * Blocks of the group "asv" (school registration) use the column names and value keys of the ASV-BW import template
 * literally (SurveyBlocksTest checks them against backend/tests/fixtures/asv/asv-bw-import.json). The page embeds all()
 * as JSON; backend/tests/js/fixtures/survey-blocks.json is the committed copy the JavaScript tests read
 * (refresh: php backend/tools/dump-survey-blocks.php > backend/tests/js/fixtures/survey-blocks.json).
 */
final class SurveyBlocks
{
    public const GROUPS = ['asv', 'general'];

    private const DIR = __DIR__ . '/../../resources/survey-templates';

    /**
     * @return list<array{key:string, block:string, group:string, param:int|string|null, element:array<string,mixed>}>
     *         key: unique per entry; block: the manifest key (for the texts); param: the value put in for the placeholder
     * @throws \RuntimeException if the manifest or a block file is missing or broken (a defect of the installation)
     */
    public static function all(?string $dir = null): array
    {
        $dir      = rtrim($dir ?? self::DIR, '/');
        $manifest = self::readJson($dir . '/manifest.json');
        $out      = [];

        foreach ($manifest['blocks'] ?? [] as $i => $entry) {
            $key   = is_array($entry) ? ($entry['key'] ?? null) : null;
            $file  = is_array($entry) ? ($entry['file'] ?? null) : null;
            $group = is_array($entry) ? ($entry['group'] ?? null) : null;
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1
                || !is_string($file) || preg_match('#^[a-z0-9_/-]+\.json$#', $file) !== 1 || str_contains($file, '..')
                || !in_array($group, self::GROUPS, true)) {
                throw new \RuntimeException("Baustein Nr. {$i} im Manifest ist unvollständig oder ungültig");
            }
            $element = self::readJson($dir . '/' . $file);

            $param = $entry['param'] ?? null;
            if ($param === null) {
                $out[] = ['key' => $key, 'block' => $key, 'group' => $group, 'param' => null, 'element' => $element];
                continue;
            }
            $placeholder = is_array($param) ? ($param['placeholder'] ?? null) : null;
            $values      = is_array($param) ? ($param['values'] ?? null) : null;
            if (!is_string($placeholder) || $placeholder === '' || !is_array($values) || $values === []) {
                throw new \RuntimeException("Baustein \"{$key}\": Parameter ohne Platzhalter oder Werte");
            }
            foreach ($values as $value) {
                if (!is_int($value) && !is_string($value)) {
                    throw new \RuntimeException("Baustein \"{$key}\": ungültiger Wert des Parameters");
                }
                $out[] = [
                    'key'     => $key . '_' . $value,
                    'block'   => $key,
                    'group'   => $group,
                    'param'   => $value,
                    'element' => self::replace($element, $placeholder, (string)$value),
                ];
            }
        }

        return $out;
    }

    /**
     * The names a block brings into the survey (questions, panels, display elements): what must not exist yet.
     *
     * @param array<string,mixed> $element
     * @return list<string>
     */
    public static function names(array $element): array
    {
        $names = [];
        $walk = static function (array $el) use (&$walk, &$names): void {
            if (is_string($el['name'] ?? null)) {
                $names[] = $el['name'];
            }
            foreach (is_array($el['elements'] ?? null) ? $el['elements'] : [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($element);
        return $names;
    }

    /** @return array<string,mixed> */
    private static function readJson(string $file): array
    {
        $text = is_file($file) ? file_get_contents($file) : false;
        if ($text === false) {
            throw new \RuntimeException('Datei fehlt: ' . basename($file));
        }
        try {
            $data = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(basename($file) . ': kein gültiges JSON (' . $e->getMessage() . ')');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new \RuntimeException(basename($file) . ': kein JSON-Objekt');
        }
        return $data;
    }

    private static function replace(mixed $node, string $placeholder, string $value): mixed
    {
        if (is_string($node)) {
            return str_replace($placeholder, $value, $node);
        }
        if (is_array($node)) {
            return array_map(static fn(mixed $child): mixed => self::replace($child, $placeholder, $value), $node);
        }
        return $node;
    }
}
