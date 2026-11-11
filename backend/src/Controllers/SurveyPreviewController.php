<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Forms\Identifiers;
use App\Forms\SurveyValidator;
use App\Forms\ThemeValidator;
use App\Repositories\FormConfigRepository;
use App\Repositories\FormDraftRepository;
use App\Repositories\FormResourceRepository;
use App\Utils\JsonEmbed;

/**
 * What the survey preview shows: the draft or the published survey of a form, plus its theme.
 *
 * The preview renders admin-authored content, so it only renders what passes today's validators (a survey stored
 * before the rules existed, or by SQL, is refused with the reasons), and the page itself is sandboxed
 * (form_preview_frame.php). Runs in the current tenant; a form of another tenant is "not found".
 */
class SurveyPreviewController
{
    public const SOURCE_DRAFT = 'draft';
    public const SOURCE_LIVE  = 'live';

    /** Errors the live preview reports per text; the full report stays with "Prüfen". */
    public const MAX_REPORTED = 5;

    public function __construct(
        private readonly FormConfigRepository $configs,
        private readonly FormResourceRepository $resources,
        private readonly FormDraftRepository $drafts,
        private readonly SurveyValidator $surveyValidator = new SurveyValidator(),
        private readonly ThemeValidator $themeValidator = new ThemeValidator(),
    ) {
    }

    /**
     * @param string $source SOURCE_DRAFT or SOURCE_LIVE (anything else counts as draft-if-present)
     * @return array{
     *   status: 'ok'|'not_found'|'nothing'|'invalid',
     *   source: ?string, has_draft: bool, has_live: bool,
     *   survey_json: ?string, theme_json: string, theme_from_backend: bool,
     *   errors: list<array{path:string,message:string}>, warnings: list<array{path:string,message:string}>
     * }
     *   nothing = there is no survey in the backend to show (it still lives as a file in the frontend)
     */
    public function load(string $formKey, string $source): array
    {
        $base = ['status' => 'not_found', 'source' => null, 'has_draft' => false, 'has_live' => false, 'survey_json' => null,
                 'theme_json' => '{}', 'theme_from_backend' => false, 'errors' => [], 'warnings' => []];

        $form = Identifiers::isValidFormKey($formKey) ? $this->configs->find($formKey) : null;
        if ($form === null) {
            return $base;
        }
        $config = $form['config'];

        $surveyName = $this->name($config['form'] ?? null);
        $live  = $surveyName !== null ? $this->resources->find(FormResourceRepository::KIND_SURVEY, $surveyName) : null;
        $draft = $this->drafts->find($formKey);

        $base['has_draft'] = $draft !== null;
        $base['has_live']  = $live !== null;

        $wantDraft = $source !== self::SOURCE_LIVE && $draft !== null;
        $text      = $wantDraft ? $draft['survey_json'] : ($live['content'] ?? null);
        if ($text === null) {
            return ['status' => 'nothing'] + $base;
        }
        $base['source'] = $wantDraft ? self::SOURCE_DRAFT : self::SOURCE_LIVE;

        ['result' => $result] = $this->surveyValidator->validate($text);
        $base['warnings'] = $result->warnings();
        if (!$result->isValid()) {
            return ['status' => 'invalid', 'errors' => $result->errors()] + $base;
        }

        $theme = $this->theme($config);
        $base['theme_json']         = $theme['theme_json'];
        $base['theme_from_backend'] = $theme['theme_from_backend'];
        array_push($base['warnings'], ...$theme['warnings']);

        try {
            return ['status' => 'ok', 'survey_json' => JsonEmbed::encode($text), 'theme_json' => JsonEmbed::encode($base['theme_json'])] + $base;
        } catch (\JsonException $e) {
            return ['status' => 'invalid', 'errors' => [['path' => '', 'message' => 'Kein gültiges JSON']]] + $base;
        }
    }

    /**
     * The theme for the live preview (AP4a): the survey comes from the editor, the frame only needs the theme.
     *
     * @return array{status:'ok'|'not_found', theme_json:string, theme_from_backend:bool, warnings:list<array{path:string,message:string}>}
     */
    public function loadTheme(string $formKey): array
    {
        $form = Identifiers::isValidFormKey($formKey) ? $this->configs->find($formKey) : null;
        if ($form === null) {
            return ['status' => 'not_found', 'theme_json' => '{}', 'theme_from_backend' => false, 'warnings' => []];
        }
        $theme = $this->theme($form['config']);
        try {
            $theme['theme_json'] = JsonEmbed::encode($theme['theme_json']);
        } catch (\JsonException $e) {
            $theme = ['theme_json' => '{}', 'theme_from_backend' => false, 'warnings' => []];
        }
        return ['status' => 'ok'] + $theme;
    }

    /**
     * May the live preview render this text (unsaved, straight from the editor)? The same rule as for stored surveys:
     * only what SurveyValidator accepts. Nothing is stored.
     *
     * @return array{status:'ok'|'invalid'|'not_found', errors:list<array{path:string,message:string}>}
     */
    public function checkText(string $formKey, string $text): array
    {
        $form = Identifiers::isValidFormKey($formKey) ? $this->configs->find($formKey) : null;
        if ($form === null) {
            return ['status' => 'not_found', 'errors' => []];
        }
        return self::verdict($this->surveyValidator, $text);
    }

    /**
     * The verdict on a text, without the database (unit-tested): at most MAX_REPORTED errors, path and message only.
     *
     * @return array{status:'ok'|'invalid', errors:list<array{path:string,message:string}>}
     */
    public static function verdict(SurveyValidator $validator, string $text): array
    {
        ['result' => $result] = $validator->validate($text);
        if ($result->isValid()) {
            return ['status' => 'ok', 'errors' => []];
        }
        $errors = array_map(
            static fn (array $e): array => ['path' => (string)$e['path'], 'message' => (string)$e['message']],
            array_slice($result->errors(), 0, self::MAX_REPORTED)
        );
        return ['status' => 'invalid', 'errors' => $errors];
    }

    /**
     * The published theme of a form, if it passes ThemeValidator.
     *
     * @param array<string,mixed> $config
     * @return array{theme_json:string, theme_from_backend:bool, warnings:list<array{path:string,message:string}>}
     */
    private function theme(array $config): array
    {
        $out = ['theme_json' => '{}', 'theme_from_backend' => false, 'warnings' => []];
        $themeName = $this->name($config['theme'] ?? null);
        $theme     = $themeName !== null ? $this->resources->find(FormResourceRepository::KIND_THEME, $themeName) : null;
        if ($theme === null) {
            return $out;
        }
        if ($this->themeValidator->validate($theme['content'])['result']->isValid()) {
            return ['theme_json' => $theme['content'], 'theme_from_backend' => true, 'warnings' => []];
        }
        $out['warnings'][] = ['path' => 'theme', 'message' => 'Das Theme enthält nicht erlaubte Inhalte und wird in der Vorschau nicht angewendet.'];
        return $out;
    }

    private function name(mixed $name): ?string
    {
        return is_string($name) && Identifiers::isValidResourceName($name) ? $name : null;
    }
}
