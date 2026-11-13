<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * Validates a SurveyJS definition before it is stored or published.
 *
 * Errors (block): not JSON / too big / no questions, elements without type or name, duplicate field names,
 * reserved field names, HTML outside the allowlist, javascript: URLs.
 * Warnings (do not block): functions in expressions SurveyJS does not know, references in expressions to fields
 * the survey does not have (the condition then never holds), choices loaded from an external URL (the visitor's
 * browser would contact a third party).
 */
final class SurveyValidator
{
    public const MAX_BYTES    = 524288; // 512 KB
    public const MAX_ELEMENTS = 2000;
    public const MAX_DEPTH    = 32;

    /**
     * Field names the system uses itself: '_fieldTypes' is metadata in anmeldungen.data, 'website_url' is the
     * honeypot field of the forms (a question with that name would be taken for a bot and silently dropped).
     */
    public const RESERVED_NAMES = ['_fieldTypes', 'website_url'];

    /** Keys whose values are expressions (not HTML), so "<" there is a comparison. */
    private const EXPRESSION_KEY = '/(If|Expression|expression)$/';

    /**
     * Expression functions the bundled SurveyJS (3.2.0, frontend/public/assets/survey.core.min.js) registers;
     * unknown ones are reported as warnings, because SurveyJS silently yields no value for them.
     * After a SurveyJS update, take the list from the bundle (SurveyValidatorTest fails until it matches):
     *   grep -oE 'Instance\.register\(\{?(name:)?"[A-Za-z]+"' frontend/public/assets/survey.core.min.js | cut -d'"' -f2 | sort -u
     */
    public const KNOWN_FUNCTIONS = [
        'age', 'avg', 'avgInArray', 'count', 'countInArray', 'currentDate', 'currentYear', 'dateAdd', 'dateDiff',
        'day', 'diffDays', 'displayValue', 'getComment', 'getDate', 'getYear', 'iif', 'isContainerReady',
        'isDisplayMode', 'max', 'maxInArray', 'min', 'minInArray', 'month', 'propertyValue', 'round', 'substring',
        'sum', 'sumInArray', 'today', 'trunc', 'weekday', 'year',
    ];

    /**
     * Operator keywords of the SurveyJS expression grammar (case-insensitive). Followed by "(" they are no
     * function call, e.g. "{a} = 1 and ({b} = 2 or {c} = 3)".
     */
    public const OPERATOR_WORDS = [
        'and', 'or', 'negate', 'empty', 'notempty', 'contains', 'contain', 'notcontains', 'notcontain',
        'anyof', 'allof', 'noneof', 'equal', 'notequal', 'greater', 'less', 'greaterorequal', 'lessorequal',
        'power', 'true', 'false',
    ];

    /**
     * References in expressions that name no field: variables SurveyJS provides itself, e.g. {item} in
     * choicesVisibleIf, {panelIndex} in dynamic panels, {row.…} in matrices. Same list as the grafischer Editor
     * (survey-builder/conditions.js, fieldKey()).
     */
    public const CONTEXT_VARIABLES = [
        'item', 'choice', 'row', 'panel', 'parentpanel', 'rootpanel', 'composite',
        'panelindex', 'visiblepanelindex', 'rowindex', 'visiblerowindex', 'rowname', 'itemindex',
    ];

    /** @var array<string,true>|null names expressions may refer to (fields, calculated values, panel.<name>); set per validate() */
    private ?array $referable = null;

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

        $this->referable = self::referableNames($survey);
        $this->scanStrings($survey, '', $result);
        $this->referable = null;

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

        // Questions in a choice option share the survey's name scope.
        foreach (is_array($element['choices'] ?? null) ? $element['choices'] : [] as $ci => $choice) {
            foreach (is_array($choice) && is_array($choice['elements'] ?? null) ? $choice['elements'] : [] as $i => $child) {
                $this->checkElement("$path.choices[$ci].elements[$i]", $child, $seen, $count, $result, $depth + 1);
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
        $this->checkReferences($path, $expr, $result);

        // Strip quoted literals and {field} references so only operators/functions remain.
        $stripped = preg_replace(['/\'[^\']*\'/', '/"[^"]*"/', '/\{[^}]*\}/'], ' ', $expr) ?? $expr;
        if (preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $stripped, $m) < 1) {
            return;
        }
        foreach (array_unique($m[1]) as $fn) {
            if (in_array(strtolower($fn), self::OPERATOR_WORDS, true)) {
                continue;
            }
            if (!in_array($fn, self::KNOWN_FUNCTIONS, true)) {
                $result->addWarning($path, "Unbekannte Funktion {$fn}() im Ausdruck (SurveyJS kennt sie nicht)");
            }
        }
    }

    /**
     * Warns about {name} references to fields the survey does not have. SurveyJS treats them as empty, so a
     * visibleIf never holds and a requiredIf never applies, without any message. "{a.b}" and "{a[0].b}" (part of a
     * dynamic panel, matrix or multiple text) check only "a"; "{panel.a}" checks the template fields of dynamic panels.
     */
    private function checkReferences(string $path, string $expr, ValidationResult $result): void
    {
        if ($this->referable === null) {
            return;
        }
        $outsideQuotes = preg_replace(['/\'(?:[^\'\\\\]|\\\\.)*\'/', '/"(?:[^"\\\\]|\\\\.)*"/'], ' ', $expr) ?? $expr;
        if (preg_match_all('/\{([^{}]*)\}/', $outsideQuotes, $m) < 1) {
            return;
        }
        $lower = [];
        foreach (array_keys($this->referable) as $name) {
            $lower[mb_strtolower($name)] ??= $name;
        }
        foreach (array_unique(array_map('trim', $m[1])) as $reference) {
            $key = self::referenceKey($reference);
            if ($key === null || isset($this->referable[$key])) {
                continue;
            }
            $near = $lower[mb_strtolower($key)] ?? null;
            $result->addWarning($path, $near !== null
                ? "Der Ausdruck verweist auf {{$reference}}, das Feld heißt aber \"{$near}\" (Groß- und Kleinschreibung zählt)"
                : "Der Ausdruck verweist auf {{$reference}}, ein Feld dieses Namens gibt es in der Survey nicht: SurveyJS behandelt es als leer");
        }
    }

    /**
     * The name a reference must match, or null when it names no field of the survey (SurveyJS context variables).
     */
    private static function referenceKey(string $reference): ?string
    {
        if ($reference === '' || str_starts_with($reference, '$')) {
            return null;
        }
        if (preg_match('/^panel\.([^.\[]+)/i', $reference, $m) === 1) {
            return 'panel.' . $m[1];
        }
        $head = preg_split('/[.\[]/', $reference, 2)[0] ?? $reference;
        if (in_array(mb_strtolower($head), self::CONTEXT_VARIABLES, true)) {
            return null;
        }
        return $head;
    }

    /**
     * Everything an expression can name: fields (including questions in choice options and the dynamic panels
     * themselves), calculated values, and the template fields of dynamic panels as "panel.<name>".
     *
     * @param array<string,mixed> $survey
     * @return array<string,true>
     */
    private static function referableNames(array $survey): array
    {
        $names = array_fill_keys(SurveyFieldExtractor::dataKeys($survey), true);
        $template = static function (mixed $list) use (&$template, &$names): void {
            foreach (is_array($list) ? $list : [] as $el) {
                if (!is_array($el)) {
                    continue;
                }
                if (is_string($el['name'] ?? null) && $el['name'] !== '' && ($el['type'] ?? null) !== 'panel') {
                    $names['panel.' . $el['name']] = true;
                }
                $template($el['elements'] ?? null);
                $template($el['templateElements'] ?? null);
            }
        };
        $walk = static function (mixed $node) use (&$walk, $template): void {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                if ($key === 'templateElements') {
                    $template($value);
                }
                $walk($value);
            }
        };
        $walk($survey['pages'] ?? null);
        $walk($survey['elements'] ?? null);
        return $names;
    }
}
