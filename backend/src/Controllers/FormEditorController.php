<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Forms\ConflictException;
use App\Forms\FormConfigFormMapper;
use App\Forms\FormConfigSchema;
use App\Forms\Identifiers;
use App\Forms\NotFoundException;
use App\Forms\SurveyFieldExtractor;
use App\Forms\ValidationResult;
use App\Repositories\FormConfigRepository;
use App\Repositories\FormDraftRepository;
use App\Repositories\FormResourceRepository;
use App\Repositories\FormRevisionRepository;
use App\Services\FormPublishService;

/**
 * Behind forms.php and form_edit.php: what the pages show and what a posted form does.
 *
 * Returns plain arrays so the pages only render and the rules stay testable. Everything runs in the
 * current tenant (TenantContext) and with the role the caller was given (FormConfigSchema::ROLE_*).
 * A form of another tenant is "not found", never "forbidden": the editor does not confirm it exists.
 */
class FormEditorController
{
    public function __construct(
        private readonly FormConfigRepository $configs,
        private readonly FormResourceRepository $resources,
        private readonly FormDraftRepository $drafts,
        private readonly FormRevisionRepository $revisions,
        private readonly FormPublishService $service,
        private readonly string $role,
        private readonly string $user,
    ) {
    }

    /**
     * @return list<array{key:string, version:string, stores_submissions:bool, survey_source:string, has_draft:bool, submissions:int}>
     */
    public function listForms(): array
    {
        $rows = [];
        foreach ($this->configs->listKeys() as $key) {
            $form = $this->configs->find($key);
            if ($form === null) {
                continue;
            }
            $config = $form['config'];
            $rows[] = [
                'key'                => $key,
                'version'            => is_string($config['version'] ?? null) ? $config['version'] : '',
                'stores_submissions' => (bool)($config['db'] ?? true),
                'survey_source'      => $this->surveyIsInDatabase($config) ? 'database' : 'file',
                'has_draft'          => $this->drafts->find($key) !== null,
                'submissions'        => $this->configs->countSubmissions($key),
            ];
        }
        return $rows;
    }

    /**
     * Everything form_edit.php shows for one form; null if the form does not exist for this tenant.
     *
     * @return array{
     *   key:string, sha256:string, config:array<string,mixed>, values:array<string,mixed>, survey_name:?string,
     *   survey_source:string, survey_fields:?list<string>, has_draft:bool, submissions:int, can_delete:bool,
     *   revisions:list<array<string,mixed>>, role:string
     * }|null
     */
    public function load(string $formKey): ?array
    {
        $form = $this->configs->find($formKey);
        if ($form === null) {
            return null;
        }
        $config = $form['config'];
        $name   = $this->surveyName($config);
        $live   = $name !== null ? $this->resources->find(FormResourceRepository::KIND_SURVEY, $name) : null;
        $survey = $live !== null ? json_decode($live['content'], true) : null;
        $count  = $this->configs->countSubmissions($formKey);

        return [
            'key'           => $formKey,
            'sha256'        => $form['sha256'],
            'config'        => $config,
            'values'        => FormConfigFormMapper::formValues($config),
            'survey_name'   => $name,
            'survey_source' => $live !== null ? 'database' : 'file',
            'survey_fields' => is_array($survey) ? SurveyFieldExtractor::dataKeys($survey) : null,
            'has_draft'     => $this->drafts->find($formKey) !== null,
            'submissions'   => $count,
            'can_delete'    => $count === 0,
            'revisions'     => $this->revisions->list($formKey, null, 30),
            'role'          => $this->role,
        ];
    }

    /**
     * Save the posted config form.
     *
     * @param array<string,mixed> $post $_POST
     * @return array{status:'saved'|'invalid'|'conflict'|'not_found', result:ValidationResult, sha256:?string, submitted:array<string,mixed>}
     */
    public function save(string $formKey, array $post): array
    {
        $cfg = is_array($post['cfg'] ?? null) ? $post['cfg'] : [];
        $token = is_string($post['sha256'] ?? null) && $post['sha256'] !== '' ? $post['sha256'] : null;

        $submitted = FormConfigFormMapper::fromPost($cfg);

        try {
            $result = $this->service->saveConfig($formKey, $submitted, $this->role, $this->user, $token);
        } catch (NotFoundException) {
            return ['status' => 'not_found', 'result' => new ValidationResult(), 'sha256' => null, 'submitted' => $submitted];
        } catch (ConflictException) {
            return ['status' => 'conflict', 'result' => new ValidationResult(), 'sha256' => null, 'submitted' => $submitted];
        }

        return ['status' => $result->ok() ? 'saved' : 'invalid', 'result' => $result->validation, 'sha256' => $result->sha256, 'submitted' => $submitted];
    }

    /**
     * Create a new form (key from the page, options like the edit form).
     *
     * @param array<string,mixed> $post
     * @return array{status:'created'|'invalid'|'exists', result:ValidationResult, key:string}
     */
    public function create(array $post): array
    {
        $key = is_string($post['form_key'] ?? null) ? trim($post['form_key']) : '';
        $cfg = is_array($post['cfg'] ?? null) ? $post['cfg'] : [];

        try {
            $result = $this->service->createForm($key, FormConfigFormMapper::fromPost($cfg), $this->role, $this->user);
        } catch (ConflictException) {
            $v = new ValidationResult();
            $v->addError('form_key', 'Ein Formular mit diesem Schlüssel gibt es schon');
            return ['status' => 'exists', 'result' => $v, 'key' => $key];
        }

        return ['status' => $result->ok() ? 'created' : 'invalid', 'result' => $result->validation, 'key' => $key];
    }

    /**
     * @return array{status:'deleted'|'refused'|'not_found', result:ValidationResult}
     */
    public function delete(string $formKey): array
    {
        try {
            $result = $this->service->deleteForm($formKey, $this->user);
        } catch (NotFoundException) {
            return ['status' => 'not_found', 'result' => new ValidationResult()];
        }
        return ['status' => $result->ok() ? 'deleted' : 'refused', 'result' => $result->validation];
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

    /** Can this role change $path? (the pages disable the input otherwise) */
    public function canEdit(string $path): bool
    {
        return FormConfigSchema::isEditableBy($path, $this->role);
    }

    /** Valid key for a new form? (live check for the create form) */
    public static function isValidNewKey(string $key): bool
    {
        return Identifiers::isValidFormKey($key);
    }

    /** @param array<string,mixed> $config */
    private function surveyName(array $config): ?string
    {
        $name = $config['form'] ?? null;
        return is_string($name) && Identifiers::isValidResourceName($name) ? $name : null;
    }

    /** @param array<string,mixed> $config */
    private function surveyIsInDatabase(array $config): bool
    {
        $name = $this->surveyName($config);
        return $name !== null && $this->resources->find(FormResourceRepository::KIND_SURVEY, $name) !== null;
    }
}
