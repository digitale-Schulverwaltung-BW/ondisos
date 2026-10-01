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

        if (($config['db'] ?? true) && array_intersect(self::EMAIL_FIELDS, $keys) === []) {
            $result->addWarning(
                'db',
                'Die Survey hat kein E-Mail-Feld (erwartet: ' . implode(', ', self::EMAIL_FIELDS) . '): '
                . 'beim Speichern der Anmeldung wird eine E-Mail-Adresse verlangt'
            );
        }

        return $result;
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
