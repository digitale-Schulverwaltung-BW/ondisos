<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * Templates and examples of the graphical survey builder (PLAN-3.2.0, AP6): what an empty survey editor offers to start
 * from. Files next to the code (backend/resources/survey-templates/, listed in manifest.json), the same for every tenant.
 *
 * - Templates (templates/*.json) are SurveyJS surveys in which an element may be a building block:
 *   {"$block": "person"} or {"$block": "erziehungsberechtigte_1", "require": ["Erz1Name", …]}. The block's element
 *   (SurveyBlocks) takes its place; "require" makes the named questions inside it required. So the ASV names and value
 *   lists exist once, in the blocks. The text is written like the SurveyJS Creator exports (two spaces).
 * - Examples (examples/*.json) are the registrations of a real school, delivered as they are (the text byte for byte).
 *   Until frontend/surveys/ goes away (PLAN-3.2.0, step 4) they are copies of those files; SurveyTemplatesTest says
 *   when they drift apart.
 */
final class SurveyTemplates
{
    public const KINDS = ['template', 'example'];

    private const DIR = __DIR__ . '/../../resources/survey-templates';

    /**
     * @return list<array{key:string, kind:string, text:string}>
     * @throws \RuntimeException a defect of the installation (missing or broken file, unknown block)
     */
    public static function all(?string $dir = null): array
    {
        $dir      = rtrim($dir ?? self::DIR, '/');
        $manifest = ResourceJson::readObject($dir . '/manifest.json');
        $blocks   = array_column(SurveyBlocks::all($dir), 'element', 'key');
        $out      = [];

        foreach (['template' => 'templates', 'example' => 'examples'] as $kind => $section) {
            foreach ($manifest[$section] ?? [] as $i => $entry) {
                $key  = is_array($entry) ? ($entry['key'] ?? null) : null;
                $file = is_array($entry) ? ($entry['file'] ?? null) : null;
                if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1 || !ResourceJson::isSafeFile($file)) {
                    throw new \RuntimeException("Eintrag Nr. {$i} unter \"{$section}\" im Manifest ist unvollständig oder ungültig");
                }
                $path = $dir . '/' . $file;
                $text = $kind === 'example'
                    ? ResourceJson::readText($path)
                    : self::encode(self::expand(ResourceJson::readObject($path), $blocks, $key));
                $out[] = ['key' => $key, 'kind' => $kind, 'text' => $text];
            }
        }

        $keys = array_column($out, 'key');
        if (count($keys) !== count(array_unique($keys))) {
            throw new \RuntimeException('Schlüssel von Vorlagen und Beispielen müssen eindeutig sein');
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $node
     * @param array<string,array<string,mixed>> $blocks
     * @return array<string,mixed>
     */
    private static function expand(array $node, array $blocks, string $template): array
    {
        foreach (['pages', 'elements'] as $listKey) {
            if (!is_array($node[$listKey] ?? null)) {
                continue;
            }
            foreach ($node[$listKey] as $i => $child) {
                if (!is_array($child)) {
                    continue;
                }
                if (array_key_exists('$block', $child)) {
                    $node[$listKey][$i] = self::block($child, $blocks, $template);
                } else {
                    $node[$listKey][$i] = self::expand($child, $blocks, $template);
                }
            }
        }
        return $node;
    }

    /**
     * @param array<string,mixed> $ref {"$block": key, "require"?: list<string>}
     * @param array<string,array<string,mixed>> $blocks
     * @return array<string,mixed>
     */
    private static function block(array $ref, array $blocks, string $template): array
    {
        $key = $ref['$block'];
        if (!is_string($key) || !isset($blocks[$key])) {
            throw new \RuntimeException("Vorlage \"{$template}\": unbekannter Baustein " . json_encode($key));
        }
        $unknown = array_diff(array_keys($ref), ['$block', 'require']);
        if ($unknown !== []) {
            throw new \RuntimeException("Vorlage \"{$template}\": unbekannte Angabe " . implode(', ', $unknown) . " bei Baustein \"{$key}\"");
        }
        $element = $blocks[$key];
        $require = $ref['require'] ?? [];
        if (!is_array($require)) {
            throw new \RuntimeException("Vorlage \"{$template}\": \"require\" muss eine Liste sein");
        }
        foreach ($require as $name) {
            $found = false;
            $element = self::setRequired($element, (string)$name, $found);
            if (!$found) {
                throw new \RuntimeException("Vorlage \"{$template}\": Baustein \"{$key}\" hat kein Feld \"{$name}\"");
            }
        }
        return $element;
    }

    /**
     * @param array<string,mixed> $element
     * @return array<string,mixed>
     */
    private static function setRequired(array $element, string $name, bool &$found): array
    {
        if (($element['name'] ?? null) === $name && ($element['type'] ?? null) !== 'panel') {
            $found = true;
            // after "title" when there is one, like the Creator writes it (order of keys stays otherwise)
            $out = [];
            foreach ($element as $k => $v) {
                if ($k === 'isRequired') {
                    continue;
                }
                $out[$k] = $v;
                if ($k === 'title') {
                    $out['isRequired'] = true;
                }
            }
            return isset($out['isRequired']) ? $out : $out + ['isRequired' => true];
        }
        foreach (is_array($element['elements'] ?? null) ? $element['elements'] : [] as $i => $child) {
            if (is_array($child)) {
                $element['elements'][$i] = self::setRequired($child, $name, $found);
            }
        }
        return $element;
    }

    /** JSON like JSON.stringify(value, null, 2): two spaces, Unicode and slashes as they are, newline at the end. */
    private static function encode(array $survey): string
    {
        $json = json_encode($survey, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        // json_encode indents with four spaces; strings never contain a raw line break, so every leading run is indentation
        return preg_replace_callback('/^( +)/m', static fn(array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json) . "\n";
    }
}
