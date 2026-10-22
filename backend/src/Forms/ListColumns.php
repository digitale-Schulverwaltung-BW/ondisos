<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * Extra columns of the registration list, taken from the fields of a survey (reine Logik).
 *
 * Only fields whose answer fits in a table cell are offered (text, choice, yes/no, rating, calculated values);
 * uploads, signatures, matrices and dynamic panels are not. Choice values are shown with their choice text,
 * sorted in choice order and can be filtered.
 *
 * The SQL pieces only contain placeholders; field names go in as bound JSON paths.
 */
final class ListColumns
{
    /** Question types that can be shown as a column. */
    public const TYPES = ['text', 'comment', 'radiogroup', 'dropdown', 'checkbox', 'tagbox', 'boolean', 'rating', 'expression'];

    /** Most extra columns at once (the table gets too wide otherwise). */
    public const MAX_COLUMNS = 8;

    /** Prefix of the sort parameter for an extra column (sort=f:<field name>). */
    public const SORT_PREFIX = 'f:';

    /** Most generated rating steps that are still offered as filter choices. */
    private const MAX_RATING_STEPS = 20;

    /**
     * @param array<string,mixed> $survey decoded survey definition
     * @param array{yes?:string, no?:string, other?:string} $labels default texts for yes/no and the "other" choice
     * @return array<string, array{name:string, label:string, type:string, choices:array<string,string>, values:array<string,mixed>, multi:bool, numeric:bool, date:bool}>
     *         field name => column (form order); choices = value key => text (choice order), values = value key => stored value
     */
    public static function catalog(array $survey, array $labels = []): array
    {
        $columns = [];
        foreach (SurveyFieldExtractor::elements($survey) as $name => $element) {
            $column = self::column($name, $element, $labels);
            if ($column !== null) {
                $columns[$name] = $column;
            }
        }
        foreach (($survey['calculatedValues'] ?? []) as $calculated) {
            $name = is_array($calculated) ? ($calculated['name'] ?? null) : null;
            if (is_string($name) && $name !== '' && !isset($columns[$name])) {
                $columns[$name] = self::make($name, $name, 'expression');
            }
        }
        return $columns;
    }

    /**
     * The requested column names that exist in the catalog, in catalog order, at most MAX_COLUMNS.
     *
     * @param array<string,array<string,mixed>> $catalog
     * @return list<string>
     */
    public static function select(mixed $requested, array $catalog): array
    {
        $wanted = is_array($requested) ? array_filter($requested, 'is_string') : [];
        $selected = array_values(array_filter(array_keys($catalog), fn($name) => in_array((string)$name, $wanted, true)));
        return array_map('strval', array_slice($selected, 0, self::MAX_COLUMNS));
    }

    /**
     * Text of a stored answer for the table cell ('' when there is none).
     *
     * @param array{choices:array<string,string>, date:bool} $column
     */
    public static function format(mixed $value, array $column): string
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                return '';
            }
            $parts = array_map(fn($item) => is_scalar($item) ? self::format($item, $column) : '', $value);
            return implode(', ', array_filter($parts, fn($part) => $part !== ''));
        }
        if ($value === null || $value === '') {
            return '';
        }
        $key = self::key($value);
        if (isset($column['choices'][$key])) {
            return $column['choices'][$key];
        }
        if ($column['date'] && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $key, $m) === 1) {
            return "{$m[3]}.{$m[2]}.{$m[1]}";
        }
        return $key;
    }

    /** Whether the column offers a filter (only choice columns). */
    public static function filterable(array $column): bool
    {
        return $column['choices'] !== [];
    }

    /**
     * ORDER BY clause for the column: empty answers last in both directions, then choice order for choice columns
     * (unknown values after the choices), numeric for numbers and ratings, otherwise text without regard to case.
     *
     * @param array{name:string, choices:array<string,string>, multi:bool, numeric:bool} $column
     * @return array{sql:string, params:list<string>}
     */
    public static function orderBy(array $column, string $direction): array
    {
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        // Several answers (checkbox): sort by the first one
        $path  = self::jsonPath($column['name']) . ($column['multi'] ? '[0]' : '');
        $value = 'JSON_UNQUOTE(JSON_EXTRACT(data, ?))';
        $empty = "COALESCE({$value}, '') = ''";

        if ($column['choices'] !== []) {
            // Positions are our own integers; only the choice values are bound
            $keys = array_map('strval', array_keys($column['choices']));
            $sql  = "CASE {$value}";
            foreach (array_keys($keys) as $i) {
                $sql .= " WHEN ? THEN {$i}";
            }
            $sql .= ' ELSE ' . count($keys) . ' END';
            return ['sql' => "{$empty}, {$sql} {$direction}", 'params' => [$path, $path, ...$keys]];
        }
        $sql = $column['numeric']
            ? "CAST({$value} AS DECIMAL(20,6))"
            : "CONVERT({$value} USING utf8mb4) COLLATE utf8mb4_unicode_ci";
        return ['sql' => "{$empty}, {$sql} {$direction}", 'params' => [$path, $path]];
    }

    /**
     * WHERE condition for a filter value of a choice column; null when the value is not one of its choices.
     *
     * @param array{name:string, choices:array<string,string>, values:array<string,mixed>, multi:bool} $column
     * @return array{sql:string, params:list<string>}|null
     */
    public static function filterCondition(array $column, string $key): ?array
    {
        if (!array_key_exists($key, $column['choices'])) {
            return null;
        }
        $path = self::jsonPath($column['name']);
        if ($column['multi']) {
            // The stored list contains the value (with its JSON type, so 1 and "1" stay apart)
            $candidate = json_encode($column['values'][$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return ['sql' => 'JSON_CONTAINS(data, ?, ?)', 'params' => [(string)$candidate, $path]];
        }
        return ['sql' => 'JSON_UNQUOTE(JSON_EXTRACT(data, ?)) = ?', 'params' => [$path, $key]];
    }

    /** JSON path of a top-level key; quoted, so names with spaces or dots work. */
    public static function jsonPath(string $name): string
    {
        return '$."' . addcslashes($name, '"\\') . '"';
    }

    /**
     * Key of a stored scalar value, as MySQL's JSON_UNQUOTE writes it (true → "true").
     */
    public static function key(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * @param array<string,mixed> $element
     * @param array{yes?:string, no?:string, other?:string} $labels
     * @return array{name:string, label:string, type:string, choices:array<string,string>, values:array<string,mixed>, multi:bool, numeric:bool, date:bool}|null
     */
    private static function column(string $name, array $element, array $labels): ?array
    {
        $type = is_string($element['type'] ?? null) ? $element['type'] : '';
        if (!in_array($type, self::TYPES, true)) {
            return null;
        }
        $inputType = is_string($element['inputType'] ?? null) ? $element['inputType'] : 'text';
        if ($type === 'text' && $inputType === 'password') {
            return null;
        }
        // Titles often end with a colon ("Vorname:"), which looks odd in a column header
        $label  = rtrim(self::text($element['title'] ?? null) ?? $name, " \t:");
        $label  = $label !== '' ? $label : $name;
        $column = self::make($name, $label, $type);

        switch ($type) {
            case 'radiogroup':
            case 'dropdown':
            case 'checkbox':
            case 'tagbox':
                $column = self::withChoices($column, $element['choices'] ?? null);
                if (($element['showOtherItem'] ?? $element['hasOther'] ?? false) === true) {
                    $column['choices']['other'] = self::text($element['otherText'] ?? null) ?? ($labels['other'] ?? 'Sonstiges');
                    $column['values']['other']  = 'other';
                }
                $column['multi'] = $type === 'checkbox' || $type === 'tagbox';
                break;
            case 'boolean':
                $true  = $element['valueTrue'] ?? true;
                $false = $element['valueFalse'] ?? false;
                $column['choices'] = [
                    self::key($true)  => self::text($element['labelTrue'] ?? null) ?? ($labels['yes'] ?? 'Ja'),
                    self::key($false) => self::text($element['labelFalse'] ?? null) ?? ($labels['no'] ?? 'Nein'),
                ];
                $column['values'] = [self::key($true) => $true, self::key($false) => $false];
                break;
            case 'rating':
                $column['numeric'] = true;
                $column = is_array($element['rateValues'] ?? null) && $element['rateValues'] !== []
                    ? self::withChoices($column, $element['rateValues'])
                    : self::withRatingSteps($column, $element);
                break;
            case 'text':
                $column['numeric'] = in_array($inputType, ['number', 'range'], true);
                $column['date']    = in_array($inputType, ['date', 'datetime-local'], true);
                break;
        }
        return $column;
    }

    /**
     * @return array{name:string, label:string, type:string, choices:array<string,string>, values:array<string,mixed>, multi:bool, numeric:bool, date:bool}
     */
    private static function make(string $name, string $label, string $type): array
    {
        return ['name' => $name, 'label' => $label, 'type' => $type, 'choices' => [], 'values' => [],
                'multi' => false, 'numeric' => false, 'date' => false];
    }

    /**
     * @param array{choices:array<string,string>, values:array<string,mixed>} $column
     * @return array{choices:array<string,string>, values:array<string,mixed>}
     */
    private static function withChoices(array $column, mixed $choices): array
    {
        foreach (is_array($choices) ? $choices : [] as $choice) {
            $value = is_array($choice) ? ($choice['value'] ?? null) : $choice;
            if (!is_scalar($value)) {
                continue;
            }
            $key = self::key($value);
            $column['choices'][$key] = (is_array($choice) ? self::text($choice['text'] ?? null) : null) ?? $key;
            $column['values'][$key]  = $value;
        }
        return $column;
    }

    /**
     * Rating without explicit values: the steps from rateMin to rateMax (SurveyJS default 1 to 5).
     *
     * @param array{choices:array<string,string>, values:array<string,mixed>} $column
     * @param array<string,mixed> $element
     * @return array{choices:array<string,string>, values:array<string,mixed>}
     */
    private static function withRatingSteps(array $column, array $element): array
    {
        $min  = $element['rateMin'] ?? 1;
        $max  = $element['rateMax'] ?? 5;
        $step = $element['rateStep'] ?? 1;
        if (!is_int($min) || !is_int($max) || !is_int($step) || $step < 1 || $max < $min
            || intdiv($max - $min, $step) + 1 > self::MAX_RATING_STEPS) {
            return $column;
        }
        for ($v = $min; $v <= $max; $v += $step) {
            $column['choices'][(string)$v] = (string)$v;
            $column['values'][(string)$v]  = $v;
        }
        return $column;
    }

    /** A survey text: a plain string or the German/default entry of a localized one. */
    private static function text(mixed $text): ?string
    {
        if (is_array($text)) {
            $text = $text['de'] ?? $text['default'] ?? null;
        }
        return is_string($text) && trim($text) !== '' ? $text : null;
    }
}
