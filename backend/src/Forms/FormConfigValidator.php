<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * Validates and normalizes form configuration according to FormConfigSchema.
 *
 *  - apply():    merge values submitted by the admin form into the existing config (role-aware)
 *  - validate(): check a complete config (seed/import, before saving)
 *
 * Keys the schema does not know are never changed or dropped.
 */
final class FormConfigValidator
{
    /** Upper bound for a whole config (also keys the editor does not know): keeps form_configs rows and API answers small. */
    public const MAX_CONFIG_BYTES = 65536;

    /**
     * Merge submitted values into $existing.
     *
     * A field that is absent from $submitted stays as it is. A field that is present is validated and
     * normalized; an empty value (or an explicit null) removes the key (except bool/int, which are always stored).
     * Fields the role may not edit are preserved; submitting a *different* value for them is an error.
     *
     * @param array<string,mixed> $existing  current config (decoded config_json); may be empty for a new form
     * @param array<string,mixed> $submitted nested like the config (e.g. ['pdf' => ['enabled' => '1']])
     * @return array{config: array<string,mixed>, result: ValidationResult}
     */
    public function apply(array $existing, array $submitted, string $role): array
    {
        $result = new ValidationResult();
        $config = $existing;

        foreach (FormConfigSchema::fields() as $path => $field) {
            if (!self::hasPath($submitted, $path)) {
                continue;
            }
            $raw = self::getPath($submitted, $path);
            // An explicit null means "remove this key" (used when restoring an older config).
            [$ok, $value, $error] = $raw === null ? [true, null, null] : $this->normalize($field, $raw);

            if (!FormConfigSchema::isEditableBy($path, $role)) {
                $current = self::hasPath($existing, $path) ? self::getPath($existing, $path) : null;
                if (!$ok || !self::sameValue($value, $current)) {
                    $result->addError($path, 'Dieses Feld darf mit Ihrer Rolle nicht geändert werden');
                }
                continue;
            }

            if (!$ok) {
                $result->addError($path, (string)$error);
                continue;
            }

            if ($value === null) {
                self::unsetPath($config, $path);
            } else {
                self::setPath($config, $path, $value);
            }
        }

        return ['config' => $config, 'result' => $result];
    }

    /**
     * Build the "submitted" array that makes apply() reproduce an earlier config ($old) exactly for the
     * fields $role may edit: fields missing in $old but present in $current are submitted as null, so they are removed.
     *
     * @param array<string,mixed> $old     config of the revision being restored
     * @param array<string,mixed> $current config that is live now
     * @return array<string,mixed>
     */
    public function submissionForRestore(array $old, array $current, string $role): array
    {
        $submitted = $old;
        foreach (FormConfigSchema::fields() as $path => $field) {
            if (FormConfigSchema::isEditableBy($path, $role) && !self::hasPath($old, $path) && self::hasPath($current, $path)) {
                self::setPath($submitted, $path, null);
            }
        }
        return $submitted;
    }

    /**
     * Check every schema field present in a complete config, plus the required keys.
     *
     * @param array<string,mixed> $config
     */
    public function validate(array $config): ValidationResult
    {
        $result = new ValidationResult();

        foreach (['form', 'theme'] as $required) {
            if (!isset($config[$required]) || $config[$required] === '') {
                $result->addError($required, 'Pflichtfeld');
            }
        }

        $size = strlen((string)json_encode($config));
        if ($size > self::MAX_CONFIG_BYTES) {
            $result->addError('', 'Die Konfiguration ist zu groß (' . intdiv($size, 1024) . ' KB, höchstens ' . intdiv(self::MAX_CONFIG_BYTES, 1024) . ' KB)');
        }

        foreach (FormConfigSchema::fields() as $path => $field) {
            if (!self::hasPath($config, $path)) {
                continue;
            }
            [$ok, , $error] = $this->normalize($field, self::getPath($config, $path));
            if (!$ok) {
                $result->addError($path, (string)$error);
            }
        }

        return $result;
    }

    // =========================================================================
    // Per-type normalization
    // =========================================================================

    /**
     * @param array<string,mixed> $field
     * @return array{0:bool, 1:mixed, 2:?string} [ok, normalized value (null = "empty/remove"), error message]
     */
    private function normalize(array $field, mixed $raw): array
    {
        return match ($field['type']) {
            FormConfigSchema::TYPE_BOOL         => $this->bool($raw),
            FormConfigSchema::TYPE_STRING       => $this->string($raw, (int)($field['max'] ?? 255), false),
            FormConfigSchema::TYPE_TEXT         => $this->string($raw, (int)($field['max'] ?? 2000), true),
            FormConfigSchema::TYPE_INT          => $this->int($raw, (int)($field['min'] ?? PHP_INT_MIN), (int)($field['max'] ?? PHP_INT_MAX)),
            FormConfigSchema::TYPE_EMAIL_LIST   => $this->emailList($raw, (int)($field['maxItems'] ?? 20)),
            FormConfigSchema::TYPE_NAME_LIST    => $this->nameList($raw, (int)($field['maxItems'] ?? 100)),
            FormConfigSchema::TYPE_FIELD_FILTER => $this->fieldFilter($raw, (int)($field['maxItems'] ?? 100)),
            FormConfigSchema::TYPE_DATE         => $this->date($raw),
            FormConfigSchema::TYPE_TIME         => $this->time($raw),
            FormConfigSchema::TYPE_SECTIONS     => $this->sections($raw, (int)($field['maxItems'] ?? 10)),
            FormConfigSchema::TYPE_RESOURCE     => $this->resource($raw),
            FormConfigSchema::TYPE_PATH         => $this->path($raw, (int)($field['max'] ?? 255)),
            default                             => [false, null, 'Unbekannter Feldtyp'],
        };
    }

    /** @return array{0:bool,1:mixed,2:?string} */
    private function bool(mixed $raw): array
    {
        if (is_bool($raw)) {
            return [true, $raw, null];
        }
        if (is_int($raw) && ($raw === 0 || $raw === 1)) {
            return [true, $raw === 1, null];
        }
        if (is_string($raw)) {
            $v = strtolower(trim($raw));
            if (in_array($v, ['1', 'on', 'true', 'yes'], true)) {
                return [true, true, null];
            }
            if (in_array($v, ['', '0', 'off', 'false', 'no'], true)) {
                return [true, false, null];
            }
        }
        return [false, null, 'Ungültiger Ja/Nein-Wert'];
    }

    /** @return array{0:bool,1:mixed,2:?string} */
    private function string(mixed $raw, int $max, bool $multiline): array
    {
        if ($raw === null) {
            return [true, null, null];
        }
        if (!is_string($raw)) {
            return [false, null, 'Text erwartet'];
        }
        $value = trim(str_replace("\r\n", "\n", $raw));
        $pattern = $multiline ? '/[\x00-\x09\x0b-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/';
        if (preg_match($pattern, $value) === 1) {
            return [false, null, 'Enthält unzulässige Steuerzeichen'];
        }
        if (mb_strlen($value) > $max) {
            return [false, null, "Maximal {$max} Zeichen"];
        }
        return [true, $value === '' ? null : $value, null];
    }

    /** @return array{0:bool,1:mixed,2:?string} */
    private function int(mixed $raw, int $min, int $max): array
    {
        if ($raw === null || $raw === '') {
            return [true, null, null];
        }
        if (is_string($raw) && preg_match('/^\s*-?\d+\s*$/', $raw) === 1) {
            $raw = (int)trim($raw);
        }
        if (!is_int($raw)) {
            return [false, null, 'Ganze Zahl erwartet'];
        }
        if ($raw < $min || $raw > $max) {
            return [false, null, "Wert muss zwischen {$min} und {$max} liegen"];
        }
        return [true, $raw, null];
    }

    /** @return array{0:bool,1:mixed,2:?string} */
    private function emailList(mixed $raw, int $maxItems): array
    {
        $items = $this->toList($raw, '/[,;\n]+/');
        if ($items === null) {
            return [false, null, 'Liste von E-Mail-Adressen erwartet'];
        }
        foreach ($items as $mail) {
            if (filter_var($mail, FILTER_VALIDATE_EMAIL) === false || strlen($mail) > 254) {
                return [false, null, "Ungültige E-Mail-Adresse: {$mail}"];
            }
        }
        $items = array_values(array_unique($items));
        if (count($items) > $maxItems) {
            return [false, null, "Maximal {$maxItems} Adressen"];
        }
        return [true, $items === [] ? null : $items, null];
    }

    /** @return array{0:bool,1:mixed,2:?string} */
    private function nameList(mixed $raw, int $maxItems): array
    {
        $items = $this->toList($raw, '/\n+/');
        if ($items === null) {
            return [false, null, 'Liste von Feldnamen erwartet'];
        }
        foreach ($items as $name) {
            if (mb_strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
                return [false, null, 'Ungültiger Feldname'];
            }
        }
        $items = array_values(array_unique($items));
        if (count($items) > $maxItems) {
            return [false, null, "Maximal {$maxItems} Einträge"];
        }
        return [true, $items === [] ? null : $items, null];
    }

    /** @return array{0:bool,1:mixed,2:?string} */
    private function fieldFilter(mixed $raw, int $maxItems): array
    {
        if (is_string($raw) && strtolower(trim($raw)) === 'all') {
            return [true, 'all', null];
        }
        return $this->nameList($raw, $maxItems);
    }

    /** @return array{0:bool,1:mixed,2:?string} */
    private function date(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [true, null, null];
        }
        if (is_string($raw) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $raw, $m) === 1
            && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return [true, $raw, null];
        }
        return [false, null, 'Datum im Format JJJJ-MM-TT erwartet'];
    }

    /** @return array{0:bool,1:mixed,2:?string} */
    private function time(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [true, null, null];
        }
        if (is_string($raw) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $raw) === 1) {
            return [true, $raw, null];
        }
        return [false, null, 'Uhrzeit im Format HH:MM erwartet'];
    }

    /** @return array{0:bool,1:mixed,2:?string} */
    private function sections(mixed $raw, int $maxItems): array
    {
        if ($raw === null || $raw === '') {
            return [true, null, null];
        }
        if (!is_array($raw)) {
            return [false, null, 'Liste von Abschnitten erwartet'];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                return [false, null, 'Ungültiger Abschnitt'];
            }
            [$okT, $title, $errT] = $this->string($row['title'] ?? null, 200, false);
            [$okC, $content, $errC] = $this->string($row['content'] ?? null, 5000, true);
            if (!$okT || !$okC) {
                return [false, null, $errT ?? $errC];
            }
            if ($title === null && $content === null) {
                continue; // empty row of the admin form
            }
            $out[] = ['title' => $title ?? '', 'content' => $content ?? ''];
        }
        if (count($out) > $maxItems) {
            return [false, null, "Maximal {$maxItems} Abschnitte"];
        }
        return [true, $out === [] ? null : $out, null];
    }

    /** @return array{0:bool,1:mixed,2:?string} */
    private function resource(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [true, null, null];
        }
        if (is_string($raw) && Identifiers::isValidResourceName($raw)) {
            return [true, $raw, null];
        }
        return [false, null, 'Ungültiger Dateiname (erlaubt: Kleinbuchstaben, Ziffern, _ und -, Endung .json)'];
    }

    /** @return array{0:bool,1:mixed,2:?string} */
    private function path(mixed $raw, int $max): array
    {
        // Existing configs use "false" for "no logo".
        if ($raw === null || $raw === '' || $raw === false) {
            return [true, null, null];
        }
        if (!is_string($raw) || strlen($raw) > $max
            || preg_match('#^[A-Za-z0-9._/\-]+$#D', $raw) !== 1
            || str_contains($raw, '..')) {
            return [false, null, 'Ungültiger Pfad'];
        }
        return [true, $raw, null];
    }

    /**
     * Accepts a list of strings, or one string split by $separator. Items are trimmed; empty ones dropped.
     *
     * @return list<string>|null null if the input is neither
     */
    private function toList(mixed $raw, string $separator): ?array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_string($raw)) {
            $raw = preg_split($separator, $raw) ?: [];
        }
        if (!is_array($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                return null;
            }
            $item = trim($item);
            if ($item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }

    // =========================================================================
    // Dotted-path helpers
    // =========================================================================

    /** @param array<string,mixed> $data */
    private static function hasPath(array $data, string $path): bool
    {
        $node = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return false;
            }
            $node = $node[$segment];
        }
        return true;
    }

    /** @param array<string,mixed> $data */
    private static function getPath(array $data, string $path): mixed
    {
        $node = $data;
        foreach (explode('.', $path) as $segment) {
            $node = $node[$segment] ?? null;
        }
        return $node;
    }

    /** @param array<string,mixed> $data */
    private static function setPath(array &$data, string $path, mixed $value): void
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

    /** @param array<string,mixed> $data */
    private static function unsetPath(array &$data, string $path): void
    {
        $segments = explode('.', $path);
        $last     = array_pop($segments);
        $node     = &$data;
        foreach ($segments as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                return;
            }
            $node = &$node[$segment];
        }
        unset($node[$last]);
        // Drop parent containers that became empty ("pdf" => []) so no empty shells are stored.
        if ($segments !== []) {
            $parentPath = implode('.', $segments);
            if (self::getPath($data, $parentPath) === []) {
                self::unsetPath($data, $parentPath);
            }
        }
    }

    /** Compares values the way the editor would see them (list order matters, 'all' vs list differ). */
    private static function sameValue(mixed $a, mixed $b): bool
    {
        // false (legacy "no logo") and null (empty) mean the same.
        $empty = static fn (mixed $v): bool => $v === null || $v === '' || $v === false || $v === [];
        if ($empty($a) && $empty($b)) {
            return true;
        }
        return $a === $b;
    }
}
