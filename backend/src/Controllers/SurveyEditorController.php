<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Forms\ConflictException;
use App\Forms\Identifiers;
use App\Forms\JsonLocator;
use App\Forms\NotFoundException;
use App\Forms\SurveyDiff;
use App\Forms\SurveyFieldExtractor;
use App\Forms\SurveyValidator;
use App\Forms\ValidationResult;
use App\Repositories\FormConfigRepository;
use App\Repositories\FormDraftRepository;
use App\Repositories\FormResourceRepository;
use App\Repositories\FormRevisionRepository;
use App\Services\FormPublishService;

/**
 * Behind form_survey.php: view model and actions of the survey editor (paste → check → draft → publish).
 *
 * Plain arrays in and out, so the rules are testable. Runs in the current tenant (TenantContext).
 * A form of another tenant is "not found".
 */
class SurveyEditorController
{
    public function __construct(
        private readonly FormConfigRepository $configs,
        private readonly FormResourceRepository $resources,
        private readonly FormDraftRepository $drafts,
        private readonly FormRevisionRepository $revisions,
        private readonly FormPublishService $service,
        private readonly string $role,
        private readonly string $user,
        private readonly SurveyValidator $validator = new SurveyValidator(),
    ) {
    }

    /**
     * @return array{
     *   key:string, survey_name:?string, current_version:string, suggested_version:string,
     *   live: array{content:string, sha256:string, updated_at:?string, updated_by:?string, fields:list<string>}|null,
     *   draft: array{content:string, updated_at:?string, updated_by:?string, stale:bool}|null,
     *   editor_text:string, revisions:list<array<string,mixed>>
     * }|null null if the form does not exist for this tenant
     */
    public function load(string $formKey): ?array
    {
        $form = $this->configs->find($formKey);
        if ($form === null) {
            return null;
        }
        $config = $form['config'];
        $name   = $this->surveyName($config);
        $row    = $name !== null ? $this->resources->find(FormResourceRepository::KIND_SURVEY, $name) : null;
        $draft  = $this->drafts->find($formKey);

        $live = null;
        if ($row !== null) {
            $decoded = json_decode($row['content'], true);
            $live = [
                'content'    => $row['content'],
                'sha256'     => $row['sha256'],
                'updated_at' => $row['updated_at'],
                'updated_by' => $row['updated_by'],
                'fields'     => is_array($decoded) ? SurveyFieldExtractor::dataKeys($decoded) : [],
            ];
        }

        $current = is_string($config['version'] ?? null) ? $config['version'] : '';

        return [
            'key'               => $formKey,
            'survey_name'       => $name,
            'current_version'   => $current,
            'suggested_version' => self::suggestVersion($current),
            'live'              => $live,
            'draft'             => $draft === null ? null : [
                'content'    => $draft['survey_json'],
                'updated_at' => $draft['updated_at'],
                'updated_by' => $draft['updated_by'],
                'stale'      => ($live['sha256'] ?? null) !== $draft['based_on_sha'],
            ],
            'editor_text'       => $draft['survey_json'] ?? ($live['content'] ?? ''),
            'revisions'         => $this->revisions->list($formKey, FormRevisionRepository::KIND_SURVEY, 30),
        ];
    }

    /**
     * Check a text without storing anything: findings with line numbers, field changes and a diff against the live survey.
     *
     * @return array{
     *   valid:bool, required_fields:?array{applies:bool, name:array{present:bool,field:?string,required:bool}, email:array{present:bool,field:?string,required:bool}},
     *   errors:list<array{path:string,message:string,line:?int,column:?int}>,
     *   warnings:list<array{path:string,message:string,line:?int}>,
     *   fields:?array{added:list<string>,removed:list<string>,type_changed:list<array{name:string,from:string,to:string}>},
     *   diff:?array<string,mixed>, first_publish:bool
     * }|null null if the form does not exist
     */
    public function check(string $formKey, string $text): ?array
    {
        $form = $this->configs->find($formKey);
        if ($form === null) {
            return null;
        }
        $config = $form['config'];
        $name   = $this->surveyName($config);
        $live   = $name !== null ? $this->resources->find(FormResourceRepository::KIND_SURVEY, $name) : null;

        $text    = trim($text);
        $located = JsonLocator::analyze($text);
        ['result' => $result, 'survey' => $survey] = $this->validator->validate($text);

        $errors = [];
        if ($located['error'] !== null) {
            // The validator already says "not valid JSON"; replace it by the precise position.
            $errors[] = ['path' => '', 'message' => $located['error']['message'], 'line' => $located['error']['line'], 'column' => $located['error']['column']];
        } else {
            foreach ($result->errors() as $e) {
                $errors[] = ['path' => $e['path'], 'message' => $e['message'], 'line' => $located['lines'][$e['path']] ?? null, 'column' => null];
            }
        }

        $warnings = [];
        if ($survey !== null) {
            $lint = $this->linter()->lint($config, $survey);
            $result->merge($lint);
        }
        foreach ($result->warnings() as $w) {
            $warnings[] = ['path' => $w['path'], 'message' => $w['message'], 'line' => $located['lines'][$w['path']] ?? null];
        }

        $fields = null;
        $diff   = null;
        if ($survey !== null && $errors === []) {
            $liveSurvey = $live !== null ? json_decode($live['content'], true) : [];
            $fields = SurveyDiff::fields(is_array($liveSurvey) ? $liveSurvey : [], $survey);
            $diff   = $live !== null ? SurveyDiff::lines($live['content'], $text) : null;
        }

        $needed = $survey !== null ? $this->linter()->requiredFields($survey) : null;

        return [
            'valid'         => $errors === [],
            'required_fields' => $needed === null ? null : ['applies' => (bool)($config['db'] ?? true)] + $needed,
            'errors'        => $errors,
            'warnings'      => $warnings,
            'fields'        => $fields,
            'diff'          => $diff,
            'first_publish' => $live === null,
        ];
    }

    /**
     * @return array{status:'saved'|'invalid'|'not_found', report:?array<string,mixed>}
     */
    public function saveDraft(string $formKey, string $text): array
    {
        $report = $this->check($formKey, $text);
        if ($report === null) {
            return ['status' => 'not_found', 'report' => null];
        }
        if (!$report['valid']) {
            return ['status' => 'invalid', 'report' => $report];
        }

        try {
            $result = $this->service->saveDraft($formKey, $text, $this->user);
        } catch (NotFoundException) {
            return ['status' => 'not_found', 'report' => null];
        }

        return ['status' => $result->ok() ? 'saved' : 'invalid', 'report' => $report];
    }

    /**
     * Save the text as the draft and make it live. $newVersion (optional) replaces config "version" afterwards.
     *
     * @return array{status:'published'|'invalid'|'conflict'|'not_found', report:?array<string,mixed>, version_error:?string}
     */
    public function publish(string $formKey, string $text, ?string $newVersion, ?string $note): array
    {
        $saved = $this->saveDraft($formKey, $text);
        if ($saved['status'] !== 'saved') {
            return ['status' => $saved['status'], 'report' => $saved['report'], 'version_error' => null];
        }

        try {
            $result = $this->service->publish($formKey, $this->user, $note !== null && trim($note) !== '' ? trim($note) : null);
        } catch (ConflictException) {
            return ['status' => 'conflict', 'report' => $saved['report'], 'version_error' => null];
        } catch (NotFoundException) {
            return ['status' => 'not_found', 'report' => null, 'version_error' => null];
        }
        if (!$result->ok()) {
            return ['status' => 'invalid', 'report' => $saved['report'], 'version_error' => null];
        }

        $versionError = null;
        $newVersion   = $newVersion !== null ? trim($newVersion) : null;
        if ($newVersion !== null && $newVersion !== '') {
            $v = $this->service->saveConfig($formKey, ['version' => $newVersion], $this->role, $this->user, null, 'Version bei Veröffentlichung geändert');
            if (!$v->ok()) {
                $versionError = $v->validation->errors()[0]['message'] ?? 'Version ungültig';
            }
        }

        return ['status' => 'published', 'report' => $saved['report'], 'version_error' => $versionError];
    }

    /** @return 'discarded'|'no_draft'|'not_found' */
    public function discard(string $formKey): string
    {
        try {
            return $this->service->discardDraft($formKey, $this->user) ? 'discarded' : 'no_draft';
        } catch (NotFoundException) {
            return 'not_found';
        }
    }

    /**
     * @return array{status:'restored'|'invalid'|'not_found', result:ValidationResult}
     */
    public function restore(string $formKey, int $revisionId): array
    {
        try {
            $result = $this->service->restoreRevision($formKey, $revisionId, $this->role, $this->user);
        } catch (NotFoundException) {
            return ['status' => 'not_found', 'result' => new ValidationResult()];
        }
        return ['status' => $result->ok() ? 'restored' : 'invalid', 'result' => $result->validation];
    }

    /**
     * "2026-01-v2" → "2026-01-v3"; anything else → "<year>-<month>-v1".
     */
    public static function suggestVersion(string $current, ?\DateTimeInterface $now = null): string
    {
        if (preg_match('/^(.*v)(\d+)$/D', $current, $m) === 1) {
            return $m[1] . ((int)$m[2] + 1);
        }
        return ($now ?? new \DateTimeImmutable())->format('Y-m') . '-v1';
    }

    private function linter(): \App\Forms\SurveyLinter
    {
        return new \App\Forms\SurveyLinter();
    }

    /** @param array<string,mixed> $config */
    private function surveyName(array $config): ?string
    {
        $name = $config['form'] ?? null;
        return is_string($name) && Identifiers::isValidResourceName($name) ? $name : null;
    }
}
