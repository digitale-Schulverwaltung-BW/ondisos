<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * Translates between the config array and the HTML form of the editor.
 *
 * The form posts nested fields (cfg[pdf][enabled]=1, cfg[pdf][pre_sections][0][title]=…). fromPost() turns that
 * into the "submitted" array FormConfigValidator::apply() expects; formValues() prepares stored values for display.
 * Only fields of FormConfigSchema are read, so a crafted request cannot smuggle other keys into the config.
 */
final class FormConfigFormMapper
{
    /** Suffix of the helper field that carries the "all fields / only these" choice of a field filter. */
    public const MODE_SUFFIX = '__mode';

    /**
     * @param array<string,mixed> $cfg the posted cfg[...] array
     * @return array<string,mixed> nested like the config; only fields that were posted
     */
    public static function fromPost(array $cfg): array
    {
        $submitted = [];

        foreach (FormConfigSchema::fields() as $path => $field) {
            [$present, $raw] = self::lookup($cfg, $path);

            if ($field['type'] === FormConfigSchema::TYPE_FIELD_FILTER) {
                [$modePresent, $mode] = self::lookup($cfg, $path . self::MODE_SUFFIX);
                if ($modePresent) {
                    $present = true;
                    $raw = $mode === 'list' ? ($raw ?? '') : 'all';
                }
            }
            if (!$present) {
                continue;
            }

            self::set($submitted, $path, self::clean($field['type'], $raw));
        }

        return $submitted;
    }

    /**
     * Stored config → values for the input elements, keyed by dotted path.
     *
     * @param array<string,mixed> $config
     * @return array<string, mixed> bool for checkboxes, string for single-line/text inputs, list<string> for name lists,
     *         list<array{title:string,content:string}> for sections, 'all'|list<string> for field filters
     */
    public static function formValues(array $config): array
    {
        $values = [];
        foreach (FormConfigSchema::fields() as $path => $field) {
            [$present, $raw] = self::lookup($config, $path);

            $values[$path] = match ($field['type']) {
                FormConfigSchema::TYPE_BOOL       => $present ? (bool)$raw : ($path === 'db'),
                FormConfigSchema::TYPE_EMAIL_LIST => self::asList($raw),
                FormConfigSchema::TYPE_NAME_LIST  => self::asList($raw),
                FormConfigSchema::TYPE_FIELD_FILTER => $raw === 'all' || !$present ? 'all' : self::asList($raw),
                FormConfigSchema::TYPE_SECTIONS   => self::asSections($raw),
                FormConfigSchema::TYPE_INT, FormConfigSchema::TYPE_PATH => $present && $raw !== false && $raw !== null ? (string)$raw : '',
                default                           => $present && is_string($raw) ? $raw : '',
            };
        }
        return $values;
    }

    /**
     * The stored config with the submitted values laid over it, unvalidated. Used to show the admin's own input
     * again when validation failed, so a typo does not wipe what was typed.
     *
     * @param array<string,mixed> $config
     * @param array<string,mixed> $submitted result of fromPost()
     * @return array<string,mixed>
     */
    public static function overlay(array $config, array $submitted): array
    {
        foreach (FormConfigSchema::fields() as $path => $field) {
            [$present, $raw] = self::lookup($submitted, $path);
            if ($present) {
                self::set($config, $path, $raw);
            }
        }
        return $config;
    }

    /** @return array{0:bool,1:mixed} */
    private static function lookup(array $data, string $path): array
    {
        $node = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return [false, null];
            }
            $node = $node[$segment];
        }
        return [true, $node];
    }

    private static function set(array &$data, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $last     = array_pop($segments);
        $node     = &$data;
        foreach ($segments as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node = &$node[$segment];
        }
        $node[$last] = $value;
    }

    private static function clean(string $type, mixed $raw): mixed
    {
        if ($type === FormConfigSchema::TYPE_SECTIONS) {
            if (!is_array($raw)) {
                return $raw === '' || $raw === null ? [] : $raw;
            }
            // Rows come as [0 => [title, content], 1 => …]; keep only arrays so garbage reaches the validator as such.
            return array_values($raw);
        }
        if (is_array($raw)) {
            return array_values(array_filter($raw, 'is_string'));
        }
        return $raw;
    }

    /** @return list<string> */
    private static function asList(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[,;\n]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_filter(array_map(static fn ($v) => is_string($v) ? trim($v) : '', $raw), static fn (string $v) => $v !== ''));
    }

    /** @return list<array{title:string,content:string}> */
    private static function asSections(mixed $raw): array
    {
        $rows = [];
        if (is_array($raw)) {
            foreach ($raw as $row) {
                if (is_array($row)) {
                    $rows[] = ['title' => (string)($row['title'] ?? ''), 'content' => (string)($row['content'] ?? '')];
                }
            }
        }
        return $rows;
    }
}
