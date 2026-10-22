<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * Sample submission for the PDF preview: every question of a survey with a made-up answer.
 *
 * Text questions show their own field name, dates show 1.1.2000, numbers show 1, choice questions show their first choice,
 * yes/no questions show "Ja". The keys keep the survey order (the PDF sorts by "_fieldTypes", like real submissions);
 * "_fieldTypes" carries question and page titles in the shape survey-handler-base.js stores with a submission.
 */
final class PdfPreviewData
{
    private const DATE_INPUTS   = ['date', 'datetime-local', 'month', 'week'];
    private const NUMBER_INPUTS = ['number', 'range'];
    private const CHOICE_TYPES  = ['dropdown', 'radiogroup', 'tagbox', 'checkbox', 'ranking', 'imagepicker'];

    /**
     * The "pdf" part of a config as the renderer needs it, tolerant of unvalidated input (the preview also shows unsaved form values).
     * The logo is never taken from here: the caller decides it (PdfLogoResolver).
     *
     * @param array<string,mixed> $pdf
     * @return array<string,mixed>
     */
    public static function config(array $pdf): array
    {
        $out = ['enabled' => true];
        foreach (['title', 'header_title', 'intro_text', 'footer_text'] as $key) {
            if (is_string($pdf[$key] ?? null)) {
                $out[$key] = $pdf[$key];
            }
        }
        $include = $pdf['include_fields'] ?? 'all';
        $out['include_fields'] = is_array($include) ? self::strings($include) : 'all';
        $out['exclude_fields'] = self::strings(is_array($pdf['exclude_fields'] ?? null) ? $pdf['exclude_fields'] : []);
        foreach (['pre_sections', 'post_sections'] as $key) {
            $out[$key] = [];
            foreach (is_array($pdf[$key] ?? null) ? $pdf[$key] : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $title = is_string($row['title'] ?? null) ? $row['title'] : '';
                $content = is_string($row['content'] ?? null) ? $row['content'] : '';
                if (trim($title) !== '' || trim($content) !== '') { // an empty row of the admin form would render as an empty box
                    $out[$key][] = ['title' => $title, 'content' => $content];
                }
            }
        }
        if (array_key_exists('section_headings', $pdf)) { // the editor posts "0"/"1", a stored config true/false
            $out['section_headings'] = filter_var($pdf['section_headings'], FILTER_VALIDATE_BOOLEAN);
        }
        if (($pdf['logo'] ?? null) === false) {
            $out['logo'] = false; // "no logo" stays honoured
        }
        return $out;
    }

    /** @return list<string> */
    private static function strings(array $values): array
    {
        return array_values(array_filter($values, 'is_string'));
    }

    /**
     * @param array<string,mixed> $survey decoded survey definition
     * @return array<string,mixed> field name => sample value, plus "_fieldTypes"
     */
    public static function fromSurvey(array $survey): array
    {
        $data  = [];
        $types = [];
        foreach (is_array($survey['pages'] ?? null) ? array_values($survey['pages']) : [] as $i => $page) {
            if (!is_array($page) || !is_array($page['elements'] ?? null)) {
                continue;
            }
            $pageInfo = [
                'page'      => is_string($page['name'] ?? null) && $page['name'] !== '' ? $page['name'] : 'page' . ($i + 1), // SurveyJS default name
                'pageTitle' => self::text($page['title'] ?? null) ?? '',
            ];
            foreach ($page['elements'] as $element) {
                self::walk($element, $data, $types, $pageInfo);
            }
        }
        foreach (is_array($survey['elements'] ?? null) ? $survey['elements'] : [] as $element) { // single-page survey without "pages"
            self::walk($element, $data, $types, []);
        }
        $data['_fieldTypes'] = $types;
        return $data;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,array<string,string>> $types
     * @param array{page?:string,pageTitle?:string} $pageInfo
     */
    private static function walk(mixed $element, array &$data, array &$types, array $pageInfo): void
    {
        if (!is_array($element)) {
            return;
        }
        $type = is_string($element['type'] ?? null) ? $element['type'] : '';

        if ($type === 'panel' || $type === 'page') {
            foreach (($element['elements'] ?? []) as $child) {
                self::walk($child, $data, $types, $pageInfo);
            }
            return;
        }

        $name = $element['name'] ?? null;
        if (!is_string($name) || $name === '' || in_array($type, SurveyFieldExtractor::DISPLAY_TYPES, true) || isset($data[$name])) {
            return;
        }
        $data[$name]  = self::sample($type, $name, $element);
        $types[$name] = ['type' => $type, 'title' => self::text($element['title'] ?? null) ?? $name] + $pageInfo;
        foreach (SurveyFieldExtractor::choiceElements($element) as $child) {
            self::walk($child, $data, $types, $pageInfo);
        }
    }

    /** @param array<string,mixed> $element */
    private static function sample(string $type, string $name, array $element): mixed
    {
        if ($type === 'text') {
            $input = is_string($element['inputType'] ?? null) ? $element['inputType'] : 'text';
            if (in_array($input, self::DATE_INPUTS, true)) {
                return '2000-01-01';
            }
            if (in_array($input, self::NUMBER_INPUTS, true)) {
                return '1';
            }
            return $name;
        }
        if ($type === 'boolean') {
            return true;
        }
        if (in_array($type, self::CHOICE_TYPES, true)) {
            $first = self::firstChoice($element['choices'] ?? null);
            if ($first === null) {
                return $name;
            }
            return in_array($type, ['checkbox', 'tagbox', 'ranking'], true) ? [$first] : $first;
        }
        if ($type === 'rating') {
            return '1';
        }
        if ($type === 'file') {
            return 'beispiel.pdf';
        }
        return $name;
    }

    private static function firstChoice(mixed $choices): ?string
    {
        if (!is_array($choices) || $choices === []) {
            return null;
        }
        $first = reset($choices);
        if (is_array($first)) {
            $first = self::text($first['text'] ?? null) ?? $first['value'] ?? null;
        }
        return is_string($first) || is_int($first) ? (string)$first : null;
    }

    /** A survey text, plain or localized {"default": "…", "de": "…"} (the default text, as the survey shows it); null if missing or empty */
    private static function text(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['default'] ?? reset($value);
        }
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
