<?php
// frontend/src/Config/SurveySource.php

declare(strict_types=1);

namespace Frontend\Config;

use Frontend\Utils\JsonEmbed;

/**
 * Where the survey and theme JSON of a form come from, and how they get into the page.
 *
 * Preferred source is the backend (FormConfigLoader::surveyJson/themeJson, delivered with the config).
 * If the backend holds none for this form, the files in frontend/surveys/ are used as before (3.0).
 *
 * The returned text is meant for embedding in a <script> element: it has been validated as JSON and
 * re-encoded by JsonEmbed, so it cannot break out of the element.
 */
final class SurveySource
{
    /** Names of survey/theme files in frontend/surveys/. No path separators, no leading dot. */
    private const FILE_NAME = '/^[A-Za-z0-9_][A-Za-z0-9._-]*\.json$/D';

    /**
     * @throws \RuntimeException survey missing or not valid JSON
     */
    public static function survey(string $formKey, ?string $surveysDir = null): string
    {
        $json = FormConfigLoader::surveyJson($formKey);
        if ($json === null) {
            $json = self::readFile(self::configured($formKey, 'form'), $surveysDir, true);
        }
        return self::embeddable($json, 'Survey');
    }

    /**
     * An absent theme is not an error: the form is shown with SurveyJS defaults ("{}").
     *
     * @throws \RuntimeException theme present but not valid JSON
     */
    public static function theme(string $formKey, ?string $surveysDir = null): string
    {
        $json = FormConfigLoader::themeJson($formKey);
        if ($json === null) {
            $json = self::readFile(self::configured($formKey, 'theme'), $surveysDir, false) ?? '{}';
        }
        return self::embeddable($json, 'Theme');
    }

    /** @return string|null file name from the form config, null if unset */
    private static function configured(string $formKey, string $key): ?string
    {
        $name = FormConfig::get($formKey)[$key] ?? null;
        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @return string|null file content; null if optional and not there
     * @throws \RuntimeException
     */
    private static function readFile(?string $name, ?string $dir, bool $required): ?string
    {
        if ($name === null || preg_match(self::FILE_NAME, $name) !== 1) {
            if ($required) {
                throw new \RuntimeException('Survey definition not found');
            }
            return null;
        }

        $path = rtrim($dir ?? __DIR__ . '/../../surveys', '/') . '/' . $name;
        if (!is_file($path)) {
            if ($required) {
                throw new \RuntimeException('Survey definition not found: ' . $name);
            }
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Survey definition not readable: ' . $name);
        }
        return $content;
    }

    private static function embeddable(string $json, string $what): string
    {
        try {
            return JsonEmbed::encode($json);
        } catch (\JsonException $e) {
            throw new \RuntimeException("{$what} is not valid JSON: " . $e->getMessage());
        }
    }
}
