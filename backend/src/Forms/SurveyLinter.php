<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * Cross-checks a form configuration against its survey: names the config refers to must exist in the survey.
 *
 * Everything here is a warning: a typo in a field name does not break the form, but the prefill link,
 * the PDF filter or the mail text silently does nothing. Seeing this before publishing is the point.
 */
final class SurveyLinter
{
    /** Field names submit.php/AnmeldungValidator accept as the registrant's e-mail address. */
    public const EMAIL_FIELDS = ['email', 'email1', 'Email', 'E-mail', 'E-Mail'];

    /** Field names submit.php / AnmeldungService take as the registrant's name. */
    public const NAME_FIELDS = ['Name', 'name'];

    /**
     * @param array<string,mixed> $config decoded form config
     * @param array<string,mixed> $survey decoded survey
     */
    public function lint(array $config, array $survey): ValidationResult
    {
        $result = new ValidationResult();
        $keys   = SurveyFieldExtractor::dataKeys($survey);
        $known  = array_flip($keys);

        $this->checkNames($result, 'prefill_fields', $config['prefill_fields'] ?? [], $known);
        $this->checkNames($result, 'pdf.exclude_fields', $config['pdf']['exclude_fields'] ?? [], $known);
        $include = $config['pdf']['include_fields'] ?? [];
        if (is_array($include)) {
            $this->checkNames($result, 'pdf.include_fields', $include, $known);
        }

        $template = $config['email']['intro_template'] ?? null;
        if (is_string($template) && preg_match_all('/\{([^{}\s][^{}]*)\}/', $template, $m) > 0) {
            $this->checkNames($result, 'email.intro_template', array_unique($m[1]), $known, 'Platzhalter');
        }

        if ($config['db'] ?? true) {
            foreach ($this->requiredFields($survey) as $slot => $info) {
                $label = $slot === 'name' ? 'Name' : 'E-Mail-Adresse';
                $target = $slot === 'name' ? 'den Namen' : 'die E-Mail-Adresse';
                if (!$info['present']) {
                    $result->addWarning($slot, "Die Survey hat kein Feld für {$target} (erwartet: " . implode(', ', $slot === 'name' ? self::NAME_FIELDS : self::EMAIL_FIELDS)
                        . "): beim Speichern der Anmeldung wird sie verlangt, sonst wird die Anmeldung abgelehnt");
                } elseif (!$info['required']) {
                    $result->addWarning($slot, "Das Feld \"{$info['field']}\" ({$label}) ist nicht als Pflichtfeld markiert: bleibt es leer, wird die Anmeldung beim Speichern abgelehnt");
                }
            }
        }

        // The frontend refuses a form that stores nothing and mails nobody (FormConfig::discardsSubmissions()).
        if (!($config['db'] ?? true) && !self::hasRecipient($config['notify_email'] ?? null)) {
            $result->addWarning(
                'notify_email',
                'Ohne Empfänger und ohne Speichern im Backend würden Anmeldungen verloren gehen: das Formular wird deshalb nicht angezeigt. '
                . 'Bitte einen Empfänger eintragen oder das Speichern im Backend einschalten.'
            );
        }

        return $result;
    }

    /**
     * The two fields Ondisos needs from every form that stores its submissions: the registrant's name and e-mail address
     * (submit.php rejects a submission without them).
     *
     * @param array<string,mixed> $survey
     * @return array{name: array{present:bool, field:?string, required:bool}, email: array{present:bool, field:?string, required:bool}}
     */
    public function requiredFields(array $survey): array
    {
        $fields = SurveyFieldExtractor::fields($survey);

        $find = static function (array $accepted) use ($fields): array {
            foreach ($accepted as $name) {
                if (isset($fields[$name])) {
                    // "required" in the sense that matters: always filled when the form is submitted
                    $always = $fields[$name]['required'] && !$fields[$name]['conditional'];
                    return ['present' => true, 'field' => $name, 'required' => $always];
                }
            }
            return ['present' => false, 'field' => null, 'required' => false];
        };

        return ['name' => $find(self::NAME_FIELDS), 'email' => $find(self::EMAIL_FIELDS)];
    }

    /**
     * Contact data inside a survey (e-mail addresses, phone numbers), e.g. the secretariat of the school the survey was
     * copied from. Reported once per finding with its path; used when copying forms between schools.
     *
     * @param array<string,mixed> $survey
     */
    public function contactData(array $survey): ValidationResult
    {
        $result = new ValidationResult();
        $this->scanContact($survey, '', $result);
        return $result;
    }

    private function scanContact(mixed $node, string $path, ValidationResult $result): void
    {
        if (is_array($node)) {
            foreach ($node as $key => $value) {
                $this->scanContact($value, is_int($key) ? "{$path}[{$key}]" : ($path === '' ? (string)$key : "{$path}.{$key}"), $result);
            }
            return;
        }
        if (!is_string($node)) {
            return;
        }
        $text = html_entity_decode(strip_tags($node));
        if (preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $text, $m) === 1) {
            $result->addWarning($path, 'enthält eine E-Mail-Adresse (' . $m[0] . '): gehört sie zu dieser Schule?');
        }
        if (preg_match('/(?:\+\d{2}|\b0)[\d \/().\-]{7,}\d/', $text, $m) === 1) {
            $result->addWarning($path, 'enthält eine Telefonnummer (' . trim($m[0]) . '): gehört sie zu dieser Schule?');
        }
    }

    private static function hasRecipient(mixed $notify): bool
    {
        $list = is_array($notify) ? $notify : explode(',', (string)$notify);
        foreach ($list as $address) {
            if (is_string($address) && filter_var(trim($address), FILTER_VALIDATE_EMAIL) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed $names
     * @param array<string,int> $known
     */
    private function checkNames(ValidationResult $result, string $path, mixed $names, array $known, string $what = 'Feld'): void
    {
        if (!is_array($names)) {
            return;
        }
        foreach ($names as $name) {
            if (is_string($name) && !isset($known[$name])) {
                $result->addWarning($path, "{$what} \"{$name}\" gibt es in der Survey nicht");
            }
        }
    }
}
