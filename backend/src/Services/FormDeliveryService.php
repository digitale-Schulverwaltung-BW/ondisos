<?php
declare(strict_types=1);

namespace App\Services;

use App\Forms\Identifiers;
use App\Forms\SurveyValidator;
use App\Forms\ThemeValidator;
use App\Repositories\FormConfigRepository;
use App\Repositories\FormResourceRepository;

/**
 * Assembles what the public form-config endpoint delivers to a frontend: the live config of a form
 * plus its published survey and theme, with a version token (ETag) over all three.
 *
 * Only published state is read here. Drafts and revisions are never touched, so nothing unpublished
 * can leak through the unsigned endpoint.
 */
class FormDeliveryService
{
    public function __construct(
        private readonly FormConfigRepository $configs,
        private readonly FormResourceRepository $resources,
        private readonly SurveyValidator $surveyValidator = new SurveyValidator(),
        private readonly ThemeValidator $themeValidator = new ThemeValidator(),
    ) {
    }

    /**
     * Last line of defence before a survey/theme leaves the backend: content that does not pass today's validators
     * (stored before the rules existed, or written by SQL) is not delivered. The frontend then falls back to its file
     * or shows its "survey not found" page; visitors never receive e.g. an <img onerror> from the database.
     *
     * Call this only when a body is sent (not for 304 answers): validation parses the survey.
     *
     * @param array{config: array<string,mixed>, survey_json: ?string, theme_json: ?string, etag: string} $bundle
     * @return array{config: array<string,mixed>, survey_json: ?string, theme_json: ?string, etag: string, rejected: list<array{kind:string,errors:int,first:string}>}
     */
    public function sanitized(array $bundle): array
    {
        $rejected = [];

        if ($bundle['survey_json'] !== null) {
            $r = $this->surveyValidator->validate($bundle['survey_json'])['result'];
            if (!$r->isValid()) {
                $rejected[] = ['kind' => 'survey', 'errors' => count($r->errors()), 'first' => $r->errors()[0]['message']];
                $bundle['survey_json'] = null;
            }
        }
        if ($bundle['theme_json'] !== null) {
            $r = $this->themeValidator->validate($bundle['theme_json'])['result'];
            if (!$r->isValid()) {
                $rejected[] = ['kind' => 'theme', 'errors' => count($r->errors()), 'first' => $r->errors()[0]['message']];
                $bundle['theme_json'] = null;
            }
        }

        return $bundle + ['rejected' => $rejected];
    }

    /**
     * @return array{config: array<string,mixed>, survey_json: ?string, theme_json: ?string, etag: string}|null
     *         null if the form does not exist for the current tenant. survey_json / theme_json are null when the
     *         database holds none under the name the config points to (the frontend then falls back to its files).
     */
    public function bundle(string $formKey): ?array
    {
        $form = $this->configs->find($formKey);
        if ($form === null) {
            return null;
        }
        $config = $form['config'];

        $survey = $this->resource(FormResourceRepository::KIND_SURVEY, $config['form'] ?? null);
        $theme  = $this->resource(FormResourceRepository::KIND_THEME, $config['theme'] ?? null);

        return [
            'config'      => $config,
            'survey_json' => $survey['content'] ?? null,
            'theme_json'  => $theme['content'] ?? null,
            'etag'        => hash('sha256', $form['sha256'] . '|' . ($survey['sha256'] ?? '-') . '|' . ($theme['sha256'] ?? '-')),
        ];
    }

    /**
     * Form keys of the current tenant (for the signed forms.php status endpoint).
     *
     * @return list<string>
     */
    public function formKeys(): array
    {
        return $this->configs->listKeys();
    }

    /**
     * Does an If-None-Match header value match $etag?
     *
     * Tolerates what proxies and Apache's mod_deflate do to validators: quotes, a W/ prefix, a "-gzip"/"-br"
     * suffix, and lists ("a", "b"). "*" matches anything.
     */
    public static function etagMatches(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null || trim($ifNoneMatch) === '') {
            return false;
        }
        if (trim($ifNoneMatch) === '*') {
            return true;
        }
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            $candidate = preg_replace('/^W\//', '', $candidate) ?? $candidate;
            $candidate = trim($candidate, '"');
            $candidate = preg_replace('/-(gzip|br|deflate)$/', '', $candidate) ?? $candidate;
            if ($candidate !== '' && hash_equals($etag, $candidate)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{content: string, sha256: string}|null */
    private function resource(string $kind, mixed $name): ?array
    {
        if (!is_string($name) || !Identifiers::isValidResourceName($name)) {
            return null;
        }
        $row = $this->resources->find($kind, $name);

        return $row === null ? null : ['content' => $row['content'], 'sha256' => $row['sha256']];
    }
}
