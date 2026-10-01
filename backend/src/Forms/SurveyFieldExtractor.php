<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * Lists the data fields (questions) of a SurveyJS definition, in form order.
 *
 * Panels are walked recursively; dynamic panels count as one field (their template
 * elements are scoped to the panel). Display-only elements (html, image) are no fields.
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
    private static function collect(mixed $element, array &$fields): void
    {
        if (!is_array($element)) {
            return;
        }
        $type = is_string($element['type'] ?? null) ? $element['type'] : '';

        if ($type === 'panel' || $type === 'page') {
            foreach (($element['elements'] ?? []) as $child) {
                self::collect($child, $fields);
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
                'conditional' => !empty($element['requiredIf']) || !empty($element['visibleIf']) || !empty($element['enableIf']),
            ];
        }
    }
}
