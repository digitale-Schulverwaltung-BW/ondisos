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

        $themeName = $this->name($config['theme'] ?? null);
        $theme     = $themeName !== null ? $this->resources->find(FormResourceRepository::KIND_THEME, $themeName) : null;
        if ($theme !== null) {
            $themeResult = $this->themeValidator->validate($theme['content'])['result'];
            if ($themeResult->isValid()) {
                $base['theme_json']         = $theme['content'];
                $base['theme_from_backend'] = true;
            } else {
                $base['warnings'][] = ['path' => 'theme', 'message' => 'Das Theme enthält nicht erlaubte Inhalte und wird in der Vorschau nicht angewendet.'];
            }
        }

        try {
            return ['status' => 'ok', 'survey_json' => JsonEmbed::encode($text), 'theme_json' => JsonEmbed::encode($base['theme_json'])] + $base;
        } catch (\JsonException $e) {
            return ['status' => 'invalid', 'errors' => [['path' => '', 'message' => 'Kein gültiges JSON']]] + $base;
        }
    }

    private function name(mixed $name): ?string
    {
        return is_string($name) && Identifiers::isValidResourceName($name) ? $name : null;
    }
}
