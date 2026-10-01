<?php
declare(strict_types=1);

namespace App\Forms;

/**
 * Validates a SurveyJS theme definition (colors, fonts, CSS variables).
 *
 * Themes carry no HTML, so any "<" is rejected, as are javascript:/data: URLs.
 */
final class ThemeValidator
{
    public const MAX_BYTES = 262144; // 256 KB

    /**
     * @return array{result: ValidationResult, theme: ?array<string,mixed>}
     */
    public function validate(string $json): array
    {
        $result = new ValidationResult();

        if (strlen($json) > self::MAX_BYTES) {
            $result->addError('', 'Theme ist zu groß (maximal ' . (self::MAX_BYTES / 1024) . ' KB)');
            return ['result' => $result, 'theme' => null];
        }
        try {
            $theme = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $result->addError('', 'Kein gültiges JSON: ' . $e->getMessage());
            return ['result' => $result, 'theme' => null];
        }
        if (!is_array($theme) || ($theme !== [] && array_is_list($theme))) {
            $result->addError('', 'Das Theme muss ein JSON-Objekt sein');
            return ['result' => $result, 'theme' => null];
        }

        $this->scan($theme, '', $result);

        return ['result' => $result, 'theme' => $theme];
    }

    private function scan(mixed $node, string $path, ValidationResult $result): void
    {
        if (is_array($node)) {
            foreach ($node as $key => $value) {
                $this->scan($value, is_int($key) ? "{$path}[{$key}]" : ($path === '' ? (string)$key : "{$path}.{$key}"), $result);
            }
            return;
        }
        if (!is_string($node)) {
            return;
        }
        if (str_contains($node, '<') || HtmlPolicy::isDangerousScheme($node)) {
            $result->addError($path, 'Nicht erlaubter Inhalt im Theme (HTML oder javascript:/data:-URL)');
        } elseif (preg_match('#url\(\s*[\'"]?\s*(?:https?:)?//#i', $node) === 1) {
            $result->addWarning($path, 'Das Theme lädt eine Ressource von einem externen Server (Datenschutz)');
        }
    }
}
