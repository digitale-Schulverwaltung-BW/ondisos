<?php
declare(strict_types=1);

namespace App\Services;

use App\Forms\Identifiers;
use App\Forms\SurveyValidator;
use App\Forms\ThemeValidator;
use App\Forms\ValidationResult;
use App\Repositories\FormConfigRepository;
use App\Repositories\FormResourceRepository;
use App\Repositories\FormRevisionRepository;

/**
 * Imports survey and theme files (e.g. frontend/surveys/*.json) into the database for the current tenant.
 *
 * Used by the CLI import and, later, the admin UI. Content goes through the same validators as
 * everything the editor stores, so a file that would be rejected in the editor is rejected here.
 * Existing resources are only replaced with $overwrite; the previous state then goes to the history
 * of every form that uses the file.
 */
class SurveyImportService
{
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_SKIPPED  = 'skipped';   // exists already and overwrite was not requested
    public const STATUS_UNCHANGED = 'unchanged';
    public const STATUS_INVALID  = 'invalid';

    private const DEFAULT_THEME = 'survey_theme.json';

    public function __construct(
        private readonly FormResourceRepository $resources,
        private readonly FormConfigRepository $configs,
        private readonly FormRevisionRepository $revisions,
        private readonly SurveyValidator $surveyValidator = new SurveyValidator(),
        private readonly ThemeValidator $themeValidator = new ThemeValidator(),
        ?\Closure $audit = null,
    ) {
        $this->audit = $audit ?? static function (string $event, string $name, array $details): void {
            AuditLogger::formEvent($event, $name, $details);
        };
    }

    private \Closure $audit;

    /**
     * @param bool $dryRun validate and report what would happen, write nothing
     * @return array{status: string, validation: ValidationResult}
     */
    public function importResource(string $kind, string $name, string $json, ?string $user, bool $overwrite = false, bool $dryRun = false): array
    {
        $validation = new ValidationResult();

        if (!Identifiers::isValidResourceName($name)) {
            $validation->addError('name', 'Ungültiger Dateiname (erlaubt: Kleinbuchstaben, Ziffern, _ und -, Endung .json)');
            return ['status' => self::STATUS_INVALID, 'validation' => $validation];
        }

        $validation = $kind === FormResourceRepository::KIND_THEME
            ? $this->themeValidator->validate($json)['result']
            : $this->surveyValidator->validate($json)['result'];
        if (!$validation->isValid()) {
            if (!$dryRun) {
                ($this->audit)('survey_import_rejected', $name, ['kind' => $kind, 'errors' => count($validation->errors()), 'by' => $user]);
            }
            return ['status' => self::STATUS_INVALID, 'validation' => $validation];
        }

        $existing = $this->resources->find($kind, $name);
        if ($existing !== null) {
            if (hash_equals($existing['sha256'], hash('sha256', $json))) {
                return ['status' => self::STATUS_UNCHANGED, 'validation' => $validation];
            }
            if (!$overwrite) {
                return ['status' => self::STATUS_SKIPPED, 'validation' => $validation];
            }
            if ($kind === FormResourceRepository::KIND_SURVEY && !$dryRun) {
                $this->keepPreviousState($name, $existing['content'], $user);
            }
        }

        if (!$dryRun) {
            $this->resources->save($kind, $name, $json, $user);
            ($this->audit)('survey_imported', $name, ['kind' => $kind, 'sha256' => hash('sha256', $json), 'replaced' => $existing !== null, 'by' => $user]);
        }

        return ['status' => self::STATUS_IMPORTED, 'validation' => $validation];
    }

    /**
     * Import every *.json file of a directory (not recursive). A file is a theme if it is the theme of any form of
     * this tenant, is named survey_theme.json, or looks like one (theme keys, no pages/elements); everything else
     * is a survey.
     *
     * @return array<string, array{status: string, validation: ValidationResult, kind?: string}> file name => outcome
     */
    public function importDirectory(string $dir, ?string $user, bool $overwrite = false, bool $dryRun = false): array
    {
        $files = glob(rtrim($dir, '/') . '/*.json') ?: [];
        sort($files);
        $themes = $this->themeNames();

        $out = [];
        foreach ($files as $path) {
            $name = basename($path);

            if (is_link($path) || !is_file($path)) {
                continue;
            }
            $size = filesize($path);
            if ($size === false || $size > max(SurveyValidator::MAX_BYTES, ThemeValidator::MAX_BYTES)) {
                $v = new ValidationResult();
                $v->addError('', 'Datei ist zu groß');
                $out[$name] = ['status' => self::STATUS_INVALID, 'validation' => $v];
                continue;
            }
            $json = file_get_contents($path);
            if ($json === false) {
                $v = new ValidationResult();
                $v->addError('', 'Datei konnte nicht gelesen werden');
                $out[$name] = ['status' => self::STATUS_INVALID, 'validation' => $v];
                continue;
            }

            $isTheme = in_array($name, $themes, true) || self::looksLikeTheme($json);
            $kind    = $isTheme ? FormResourceRepository::KIND_THEME : FormResourceRepository::KIND_SURVEY;
            $out[$name] = $this->importResource($kind, $name, $json, $user, $overwrite, $dryRun) + ['kind' => $kind];
        }

        return $out;
    }

    /** A SurveyJS theme has theme keys but no questions. */
    private static function looksLikeTheme(string $json): bool
    {
        $data = json_decode($json, true);
        return is_array($data)
            && !isset($data['pages']) && !isset($data['elements'])
            && (isset($data['cssVariables']) || isset($data['themeName']));
    }

    /** @return list<string> theme file names used by this tenant's forms, plus the shared default */
    private function themeNames(): array
    {
        $names = [self::DEFAULT_THEME];
        foreach ($this->configs->listKeys() as $key) {
            $theme = $this->configs->find($key)['config']['theme'] ?? null;
            if (is_string($theme)) {
                $names[] = $theme;
            }
        }
        return array_values(array_unique($names));
    }

    /** Put the state that is about to be overwritten into the history of each form that uses the file. */
    private function keepPreviousState(string $surveyName, string $previousContent, ?string $user): void
    {
        $sha = hash('sha256', $previousContent);
        foreach ($this->configs->listKeys() as $key) {
            if (($this->configs->find($key)['config']['form'] ?? null) !== $surveyName) {
                continue;
            }
            $latest = $this->revisions->latest($key, FormRevisionRepository::KIND_SURVEY);
            if ($latest === null || !hash_equals($latest['sha256'], $sha)) {
                $this->revisions->add($key, FormRevisionRepository::KIND_SURVEY, $surveyName, $previousContent, 'Stand vor dem Import', $user);
            }
        }
    }
}
