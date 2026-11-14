<?php
// frontend/src/Utils/ConsentCheck.php

declare(strict_types=1);

namespace Frontend\Utils;

/**
 * Server-side check of the consent questions (`consent_*`) against the published survey.
 *
 * SurveyJS enforces "required" only in the browser; whoever posts to save.php or admin-ajax.php
 * directly could leave out the consent. A consent is checked here when it is unconditional:
 * the question has isRequired, and neither it nor a panel or page above it has visibleIf, enableIf
 * or requiredIf. Conditional consents (shown only for some answers) would need SurveyJS expressions
 * evaluated on the server; they are not checked, only recorded when given.
 *
 * Questions inside dynamic panels (templateElements) and inside choices (choices[].elements) are
 * conditional or nested by nature and are left out as well.
 */
final class ConsentCheck
{
    public const PREFIX = 'consent_';

    /** Field in the stored data that lists the consents given (internal, hidden from list, export, mail). */
    public const RECORD_FIELD = '_consents';

    private const CONDITIONS = ['visibleIf', 'enableIf', 'requiredIf'];

    /**
     * Names of the unconditional required consent questions of a survey.
     *
     * @param array<string,mixed> $survey decoded survey JSON
     * @return list<string>
     */
    public static function required(array $survey): array
    {
        $names = [];
        foreach (self::listOf($survey['pages'] ?? null) as $page) {
            if (is_array($page) && !self::isConditional($page)) {
                self::collect(self::listOf($page['elements'] ?? null), $names);
            }
        }
        // A survey without pages may list its elements directly
        self::collect(self::listOf($survey['elements'] ?? null), $names);

        return array_values(array_unique($names));
    }

    /**
     * Required consents not given in the submitted data.
     *
     * @param array<string,mixed> $survey
     * @param array<string,mixed> $data
     * @return list<string>
     */
    public static function missing(array $survey, array $data): array
    {
        return array_values(array_filter(
            self::required($survey),
            fn(string $name) => !self::isGiven($data[$name] ?? null)
        ));
    }

    /**
     * Names of the consent fields the visitor gave (any consent_* field with a given value).
     *
     * @param array<string,mixed> $data
     * @return list<string>
     */
    public static function given(array $data): array
    {
        $names = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && str_starts_with($key, self::PREFIX) && self::isGiven($value)) {
                $names[] = $key;
            }
        }
        sort($names);

        return $names;
    }

    /**
     * A ticked checkbox (non-empty list), a boolean true or a non-empty answer.
     * "false", 0 and empty values count as not given.
     */
    public static function isGiven(mixed $value): bool
    {
        if (is_array($value)) {
            return array_filter($value, fn($v) => $v !== null && $v !== '' && $v !== false) !== [];
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            return $v !== '' && $v !== 'false' && $v !== '0';
        }

        return $value === true || (is_int($value) && $value !== 0);
    }

    /**
     * @param list<mixed> $elements
     * @param list<string> $names
     */
    private static function collect(array $elements, array &$names): void
    {
        foreach ($elements as $element) {
            if (!is_array($element) || self::isConditional($element)) {
                continue;
            }
            $name = $element['name'] ?? null;
            if (is_string($name) && str_starts_with($name, self::PREFIX) && ($element['isRequired'] ?? false) === true) {
                $names[] = $name;
            }
            // Panels nest further questions; paneldynamic (templateElements) and choices[].elements do not count
            if (($element['type'] ?? '') === 'panel') {
                self::collect(self::listOf($element['elements'] ?? null), $names);
            }
        }
    }

    /** @param array<string,mixed> $node */
    private static function isConditional(array $node): bool
    {
        foreach (self::CONDITIONS as $key) {
            if (is_string($node[$key] ?? null) && trim($node[$key]) !== '') {
                return true;
            }
        }
        // An element hidden for good can never be ticked
        return ($node['visible'] ?? true) === false;
    }

    /** @return list<mixed> */
    private static function listOf(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
