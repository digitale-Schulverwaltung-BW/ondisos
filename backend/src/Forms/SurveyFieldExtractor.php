<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * Lists the data fields (questions) of a SurveyJS definition, in form order.
 *
 * Panels are walked recursively; dynamic panels count as one field (their template
 * elements are scoped to the panel). Display-only elements (html, image) are no fields.
 * Questions nested in a choice option (choices[].elements, SurveyJS 2) are fields of the survey like any other,
 * shown only while their option is selected (so they count as conditional).
 */
final class SurveyFieldExtractor
{
    /** Element types that show content but store no answer. */
    public const DISPLAY_TYPES = ['html', 'image'];

    /**
     * @param array<string,mixed> $survey decoded survey definition
     * @return array<string, array{type:string, title:?string, required:bool, conditional:bool}> field name => info (insertion order = form order);
     *         required = isRequired is true, conditional = required/visible only under a condition (requiredIf, visibleIf, enableIf)
     */
    public static function fields(array $survey): array
    {
        $fields = [];
        foreach (self::topLevelElements($survey) as $element) {
            self::collect($element, $fields);
        }
        return $fields;
    }

    /**
     * The definitions of the fields (same names and order as fields()), for callers that need more than type and title (choices, inputType, ...).
     *
     * @param array<string,mixed> $survey
     * @return array<string, array<string,mixed>> field name => element definition
     */
    public static function elements(array $survey): array
    {
        $elements = [];
        $walk = static function (mixed $element) use (&$walk, &$elements): void {
            if (!is_array($element)) {
                return;
            }
            $type = is_string($element['type'] ?? null) ? $element['type'] : '';
            if ($type === 'panel' || $type === 'page') {
                foreach (($element['elements'] ?? []) as $child) {
                    $walk($child);
                }
                return;
            }
            $name = $element['name'] ?? null;
            if (is_string($name) && $name !== '' && !in_array($type, self::DISPLAY_TYPES, true)) {
                $elements[$name] ??= $element;
            }
            foreach (self::choiceElements($element) as $child) {
                $walk($child);
            }
        };
        foreach (self::topLevelElements($survey) as $element) {
            $walk($element);
        }
        return $elements;
    }

    /**
     * @param array<string,mixed> $survey
     * @return list<string>
     */
    public static function fieldNames(array $survey): array
    {
        return array_keys(self::fields($survey));
    }

    /**
     * Names that can appear as keys in a submission: the fields plus the survey's calculated values.
     *
     * @param array<string,mixed> $survey
     * @return list<string>
     */
    public static function dataKeys(array $survey): array
    {
        $keys = self::fieldNames($survey);
        foreach (($survey['calculatedValues'] ?? []) as $calculated) {
            if (is_array($calculated) && is_string($calculated['name'] ?? null) && $calculated['name'] !== '') {
                $keys[] = $calculated['name'];
            }
        }
        return array_values(array_unique($keys));
    }

    /**
     * @param array<string,mixed> $survey
     * @return list<mixed>
     */
    public static function topLevelElements(array $survey): array
    {
        $out = [];
        if (isset($survey['pages']) && is_array($survey['pages'])) {
            foreach ($survey['pages'] as $page) {
                if (is_array($page) && isset($page['elements']) && is_array($page['elements'])) {
                    foreach ($page['elements'] as $el) {
                        $out[] = $el;
                    }
                }
            }
        }
        if (isset($survey['elements']) && is_array($survey['elements'])) {
            foreach ($survey['elements'] as $el) {
                $out[] = $el;
            }
        }
        return $out;
    }

    /**
     * @param mixed $element
     * @param array<string, array{type:string, title:?string, required:bool, conditional:bool}> $fields
     */
    private static function collect(mixed $element, array &$fields, bool $inChoice = false): void
    {
        if (!is_array($element)) {
            return;
        }
        $type = is_string($element['type'] ?? null) ? $element['type'] : '';

        if ($type === 'panel' || $type === 'page') {
            foreach (($element['elements'] ?? []) as $child) {
                self::collect($child, $fields, $inChoice);
            }
            return;
        }

        $name = $element['name'] ?? null;
        if (is_string($name) && $name !== '' && !in_array($type, self::DISPLAY_TYPES, true)) {
            $title = $element['title'] ?? null;
            $fields[$name] ??= [
                'type'        => $type,
                'title'       => is_string($title) ? $title : null,
                'required'    => ($element['isRequired'] ?? false) === true,
                'conditional' => $inChoice || !empty($element['requiredIf']) || !empty($element['visibleIf']) || !empty($element['enableIf']),
            ];
        }
        foreach (self::choiceElements($element) as $child) {
            self::collect($child, $fields, true);
        }
    }

    /**
     * Elements nested in the choice options of a question (choices[].elements), in choice order.
     *
     * @param array<string,mixed> $element
     * @return list<mixed>
     */
    public static function choiceElements(array $element): array
    {
        $out = [];
        foreach (is_array($element['choices'] ?? null) ? $element['choices'] : [] as $choice) {
            foreach (is_array($choice) && is_array($choice['elements'] ?? null) ? $choice['elements'] : [] as $child) {
                $out[] = $child;
            }
        }
        return $out;
    }
}
