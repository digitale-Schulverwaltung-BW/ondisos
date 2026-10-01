<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * Validates a SurveyJS definition before it is stored or published.
 *
 * Errors (block): not JSON / too big / no questions, elements without type or name, duplicate field names,
 * reserved field names, HTML outside the allowlist, javascript: URLs.
 * Warnings (do not block): functions in expressions SurveyJS does not know, choices loaded from an
 * external URL (the visitor's browser would contact a third party).
 */
final class SurveyValidator
{
    public const MAX_BYTES    = 524288; // 512 KB
    public const MAX_ELEMENTS = 2000;
    private const MAX_DEPTH   = 32;

    /** Field names the system uses for its own metadata in anmeldungen.data. */
    private const RESERVED_NAMES = ['_fieldTypes'];

    /** Keys whose values are expressions (not HTML), so "<" there is a comparison. */
    private const EXPRESSION_KEY = '/(If|Expression|expression)$/';

    /** Expression functions known to SurveyJS (built-ins); unknown ones are reported as warnings. */
    private const KNOWN_FUNCTIONS = [
        'iif', 'isContainerReady', 'isDisplayMode', 'age', 'today', 'currentDate', 'getDate', 'diffDays', 'addDays',
        'sum', 'avg', 'min', 'max', 'count', 'round', 'trunc', 'ceil', 'floor', 'abs', 'sqrt', 'pow', 'log', 'exp',
        'sin', 'cos', 'tan', 'asin', 'acos', 'atan', 'sumInArray', 'avgInArray', 'minInArray', 'maxInArray',
        'countInArray', 'indexOf', 'subStr', 'substring', 'lowerCase', 'upperCase', 'trim', 'length', 'concat',
        'year', 'month', 'day', 'weekday', 'currentYear', 'currentMonth', 'currentDay', 'now',
        'isNumber', 'isEmpty', 'notEmpty', 'contains',
    ];

    /**
     * @return array{result: ValidationResult, survey: ?array<string,mixed>} survey is null when it could not be parsed
     */
    public function validate(string $json): array
    {
        $result = new ValidationResult();

        if (strlen($json) > self::MAX_BYTES) {
            $result->addError('', 'Survey ist zu groß (maximal ' . (self::MAX_BYTES / 1024) . ' KB)');
            return ['result' => $result, 'survey' => null];
        }

        try {
            $survey = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $result->addError('', 'Kein gültiges JSON: ' . $e->getMessage());
            return ['result' => $result, 'survey' => null];
        }

        if (!is_array($survey) || array_is_list($survey)) {
            $result->addError('', 'Die Survey muss ein JSON-Objekt sein');
            return ['result' => $result, 'survey' => null];
        }

        if (SurveyFieldExtractor::topLevelElements($survey) === []) {
            $result->addError('pages', 'Die Survey enthält keine Fragen (pages[].elements fehlt oder ist leer)');
        }

        $seen  = [];
        $count = 0;
        foreach ($this->elementsWithPath($survey) as [$path, $element]) {
            $this->checkElement($path, $element, $seen, $count, $result, 0);
        }
        if ($count > self::MAX_ELEMENTS) {
            $result->addError('', 'Zu viele Elemente (maximal ' . self::MAX_ELEMENTS . ')');
        }

        $this->scanStrings($survey, '', $result);

        return ['result' => $result, 'survey' => $survey];
    }

    /**
     * @param array<string,mixed> $survey
     * @return list<array{0:string,1:mixed}>
     */
    private function elementsWithPath(array $survey): array
    {
        $out = [];
        if (isset($survey['pages']) && is_array($survey['pages'])) {
            foreach ($survey['pages'] as $pi => $page) {
                if (is_array($page) && isset($page['elements']) && is_array($page['elements'])) {
                    foreach ($page['elements'] as $ei => $el) {
                        $out[] = ["pages[$pi].elements[$ei]", $el];
                    }
                }
            }
        }
        if (isset($survey['elements']) && is_array($survey['elements'])) {
            foreach ($survey['elements'] as $ei => $el) {
                $out[] = ["elements[$ei]", $el];
            }
        }
        return $out;
    }

    /**
     * @param array<string,bool> $seen field names in the current scope
     */
    private function checkElement(string $path, mixed $element, array &$seen, int &$count, ValidationResult $result, int $depth): void
    {
        $count++;
        if ($depth > self::MAX_DEPTH) {
            $result->addError($path, 'Elemente sind zu tief verschachtelt');
            return;
        }
        if (!is_array($element)) {
            $result->addError($path, 'Element ist kein Objekt');
            return;
        }

        $type = $element['type'] ?? null;
        if (!is_string($type) || $type === '') {
            $result->addError($path . '.type', 'Element ohne "type"');
            return;
        }

        if ($type === 'panel') {
            foreach (($element['elements'] ?? []) as $i => $child) {
                $this->checkElement("$path.elements[$i]", $child, $seen, $count, $result, $depth + 1);
            }
            return;
        }

        $isDisplay = in_array($type, SurveyFieldExtractor::DISPLAY_TYPES, true);
        $name      = $element['name'] ?? null;

        if (!$isDisplay) {
            if (!is_string($name) || $name === '') {
                $result->addError($path . '.name', "Frage vom Typ \"{$type}\" ohne \"name\"");
            } elseif (in_array($name, self::RESERVED_NAMES, true)) {
                $result->addError($path . '.name', "Der Feldname \"{$name}\" ist für das System reserviert");
            } elseif (isset($seen[$name])) {
                $result->addError($path . '.name', "Feldname \"{$name}\" kommt mehrfach vor");
            } else {
                $seen[$name] = true;
            }
        }

        // Dynamic panels have their own name scope for the template elements.
        if ($type === 'paneldynamic') {
            $inner = [];
            foreach (($element['templateElements'] ?? []) as $i => $child) {
                $this->checkElement("$path.templateElements[$i]", $child, $inner, $count, $result, $depth + 1);
            }
        }
    }

    /**
     * Walks every string in the survey: HTML policy, dangerous URL schemes, expression functions.
     */
    private function scanStrings(mixed $node, string $path, ValidationResult $result): void
    {
        if (is_array($node)) {
            foreach ($node as $key => $value) {
                $childPath = is_int($key) ? "{$path}[{$key}]" : ($path === '' ? (string)$key : "{$path}.{$key}");
                if ($key === 'choicesByUrl') {
                    $result->addWarning($childPath, 'Antwortoptionen werden von einer externen URL geladen: der Browser der Besucher kontaktiert diesen Server');
                }
                $this->scanStrings($value, $childPath, $result);
            }
            return;
        }
        if (!is_string($node)) {
            return;
        }

        if (HtmlPolicy::isDangerousScheme($node)) {
            $result->addError($path, 'Nicht erlaubte URL (javascript:/data:/vbscript:)');
            return;
        }

        if (preg_match(self::EXPRESSION_KEY, $this->lastKey($path)) === 1) {
            $this->checkExpression($path, $node, $result);
            return;
        }

        if (HtmlPolicy::looksLikeHtml($node)) {
            foreach (HtmlPolicy::check($node) as $problem) {
                $result->addError($path, $problem);
            }
        }
    }

    private function lastKey(string $path): string
    {
        if (preg_match('/([A-Za-z_][A-Za-z0-9_]*)(?:\[\d+\])*$/', $path, $m) === 1) {
            return $m[1];
        }
        return '';
    }

    private function checkExpression(string $path, string $expr, ValidationResult $result): void
    {
        // Strip quoted literals and {field} references so only operators/functions remain.
        $stripped = preg_replace(['/\'[^\']*\'/', '/"[^"]*"/', '/\{[^}]*\}/'], ' ', $expr) ?? $expr;
        if (preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $stripped, $m) < 1) {
            return;
        }
        foreach (array_unique($m[1]) as $fn) {
            if (!in_array($fn, self::KNOWN_FUNCTIONS, true)) {
                $result->addWarning($path, "Unbekannte Funktion {$fn}() im Ausdruck (SurveyJS kennt sie nicht)");
            }
        }
    }
}
