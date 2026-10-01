<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\FormConfig;
use App\Forms\ConflictException;
use App\Forms\FormConfigValidator;
use App\Forms\Identifiers;
use App\Forms\NotFoundException;
use App\Forms\ServiceResult;
use App\Forms\SurveyLinter;
use App\Forms\SurveyValidator;
use App\Forms\ValidationResult;
use App\Repositories\FormConfigRepository;
use App\Repositories\FormDraftRepository;
use App\Repositories\FormResourceRepository;
use App\Repositories\FormRevisionRepository;
use mysqli;

/**
 * The form editor's write operations: create/save/delete a form config, keep a survey draft,
 * publish it, restore an earlier revision.
 *
 * Business rules live here, not in the admin pages, so they are unit-testable:
 *  - every change is validated first; validation errors write nothing,
 *  - every change runs in one transaction and leaves a revision (history = everything that was live),
 *  - optimistic locking: a stale version token raises ConflictException instead of overwriting,
 *  - a role (platform / tenant) decides which config fields may change (FormConfigSchema),
 *  - all repositories are tenant-scoped via TenantContext.
 */
class FormPublishService
{
    /** How many revisions to keep per form and kind. */
    public const KEEP_REVISIONS = 50;

    private const DEFAULT_THEME = 'survey_theme.json';

    private \Closure $audit;

    public function __construct(
        private readonly mysqli $db,
        private readonly FormConfigRepository $configs,
        private readonly FormResourceRepository $resources,
        private readonly FormDraftRepository $drafts,
        private readonly FormRevisionRepository $revisions,
        private readonly SurveyValidator $surveyValidator = new SurveyValidator(),
        private readonly FormConfigValidator $configValidator = new FormConfigValidator(),
        private readonly SurveyLinter $linter = new SurveyLinter(),
        ?\Closure $audit = null,
    ) {
        $this->audit = $audit ?? static function (string $event, string $formKey, array $details): void {
            AuditLogger::formEvent($event, $formKey, $details);
        };
    }

    // =========================================================================
    // Form config
    // =========================================================================

    /**
     * Create a form. Defaults: survey "<key>.json", the shared default theme, version 1.0.0, db = true.
     * The file names can only be overridden by a platform admin.
     *
     * @param array<string,mixed> $submitted values from the admin form (nested like the config)
     * @throws ConflictException if the form key exists
     */
    public function createForm(string $formKey, array $submitted, string $role, string $user): ServiceResult
    {
        if (!Identifiers::isValidFormKey($formKey)) {
            return ServiceResult::error('form_key', 'Ungültiger Formular-Schlüssel (erlaubt: Kleinbuchstaben, Ziffern, _ und -)');
        }

        $defaults = [
            'form'    => $formKey . '.json',
            'theme'   => self::DEFAULT_THEME,
            'version' => '1.0.0',
            'db'      => true,
        ];
        ['config' => $config, 'result' => $result] = $this->configValidator->apply($defaults, $submitted, $role);
        $result->merge($this->configValidator->validate($config));
        if (!$result->isValid()) {
            return new ServiceResult($result);
        }

        return $this->transaction(function () use ($formKey, $config, $user, $result): ServiceResult {
            $sha  = $this->configs->insert($formKey, $config);
            $revId = $this->revisions->add($formKey, FormRevisionRepository::KIND_CONFIG, null, FormConfigRepository::encode($config), 'Formular angelegt', $user);
            ($this->audit)('form_created', $formKey, ['sha256' => $sha]);
            FormConfig::reset();
            return new ServiceResult($result, $sha, $revId);
        });
    }

    /**
     * Save the config of a form (merge of the submitted fields into the stored config).
     *
     * @param array<string,mixed> $submitted
     * @param string|null $expectedSha version token from when the admin form was loaded
     * @throws NotFoundException
     * @throws ConflictException
     */
    public function saveConfig(string $formKey, array $submitted, string $role, string $user, ?string $expectedSha, string $note = 'Konfiguration gespeichert'): ServiceResult
    {
        return $this->transaction(function () use ($formKey, $submitted, $role, $user, $expectedSha, $note): ServiceResult {
            $current = $this->configs->find($formKey, forUpdate: true)
                ?? throw new NotFoundException("Formular '{$formKey}' nicht gefunden");

            if ($expectedSha !== null && !hash_equals($current['sha256'], $expectedSha)) {
                throw new ConflictException("Formular '{$formKey}' wurde zwischenzeitlich geändert");
            }

            ['config' => $config, 'result' => $result] = $this->configValidator->apply($current['config'], $submitted, $role);
            $result->merge($this->configValidator->validate($config));
            if (!$result->isValid()) {
                return new ServiceResult($result);
            }

            $newJson = FormConfigRepository::encode($config);
            if ($newJson === $current['json']) {
                $result->merge($this->lintAgainstLiveSurvey($config));
                return new ServiceResult($result, $current['sha256']); // nothing changed
            }

            $this->ensureRevision($formKey, FormRevisionRepository::KIND_CONFIG, null, $current['json'], 'Stand vor der Änderung', $user);
            $sha   = $this->configs->update($formKey, $config, $current['sha256']);
            $revId = $this->revisions->add($formKey, FormRevisionRepository::KIND_CONFIG, null, $newJson, $note, $user);
            $this->revisions->pruneOldest($formKey, FormRevisionRepository::KIND_CONFIG, self::KEEP_REVISIONS);

            ($this->audit)('form_config_saved', $formKey, ['sha256' => $sha, 'role' => $role]);
            FormConfig::reset();

            $result->merge($this->lintAgainstLiveSurvey($config));
            return new ServiceResult($result, $sha, $revId);
        });
    }

    /**
     * Delete a form's config and draft. Refused while submissions exist for it.
     * Published surveys and the revision history are kept (cheap, and they make an undo possible).
     *
     * @throws NotFoundException
     */
    public function deleteForm(string $formKey, string $user): ServiceResult
    {
        return $this->transaction(function () use ($formKey, $user): ServiceResult {
            $current = $this->configs->find($formKey, forUpdate: true)
                ?? throw new NotFoundException("Formular '{$formKey}' nicht gefunden");

            $count = $this->configs->countSubmissions($formKey);
            if ($count > 0) {
                return ServiceResult::error('form_key', "Das Formular hat {$count} Anmeldung(en) und kann nicht gelöscht werden");
            }

            $this->ensureRevision($formKey, FormRevisionRepository::KIND_CONFIG, null, $current['json'], 'Stand vor dem Löschen', $user);
            $this->drafts->delete($formKey);
            $this->configs->delete($formKey);

            ($this->audit)('form_deleted', $formKey, ['sha256' => $current['sha256']]);
            FormConfig::reset();

            return new ServiceResult(new ValidationResult());
        });
    }

    // =========================================================================
    // Survey: draft → publish
    // =========================================================================

    /**
     * Validate and store a survey draft. Nothing becomes public.
     *
     * @throws NotFoundException the form does not exist for this tenant
     */
    public function saveDraft(string $formKey, string $surveyJson, string $user): ServiceResult
    {
        $surveyJson = trim($surveyJson);
        $form = $this->configs->find($formKey) ?? throw new NotFoundException("Formular '{$formKey}' nicht gefunden");

        ['result' => $result, 'survey' => $survey] = $this->surveyValidator->validate($surveyJson);
        if (!$result->isValid() || $survey === null) {
            return new ServiceResult($result);
        }
        $name = $this->surveyName($form['config']);
        if ($name === null) {
            return ServiceResult::error('form', 'Die Formular-Konfiguration verweist auf keinen gültigen Survey-Namen');
        }
        $result->merge($this->linter->lint($form['config'], $survey));

        $live = $this->resources->find(FormResourceRepository::KIND_SURVEY, $name);
        $this->drafts->save($formKey, $surveyJson, $live['sha256'] ?? null, $user);

        ($this->audit)('form_draft_saved', $formKey, ['sha256' => hash('sha256', $surveyJson)]);

        return new ServiceResult($result, hash('sha256', $surveyJson));
    }

    /**
     * Make the draft the live survey. The previous live survey stays in the history.
     *
     * @throws NotFoundException no form or no draft
     * @throws ConflictException the live survey changed since the draft was started
     */
    public function publish(string $formKey, string $user, ?string $note = null): ServiceResult
    {
        return $this->transaction(function () use ($formKey, $user, $note): ServiceResult {
            $form  = $this->configs->find($formKey) ?? throw new NotFoundException("Formular '{$formKey}' nicht gefunden");
            $draft = $this->drafts->find($formKey, forUpdate: true)
                ?? throw new NotFoundException("Für '{$formKey}' gibt es keinen Entwurf");

            ['result' => $result, 'survey' => $survey] = $this->surveyValidator->validate($draft['survey_json']);
            if (!$result->isValid() || $survey === null) {
                return new ServiceResult($result);
            }
            $name = $this->surveyName($form['config']);
            if ($name === null) {
                return ServiceResult::error('form', 'Die Formular-Konfiguration verweist auf keinen gültigen Survey-Namen');
            }

            $live = $this->resources->find(FormResourceRepository::KIND_SURVEY, $name, forUpdate: true);
            if (($live['sha256'] ?? null) !== $draft['based_on_sha']) {
                throw new ConflictException('Die veröffentlichte Survey wurde seit Beginn des Entwurfs geändert');
            }

            $revId = $this->putSurveyLive($formKey, $name, $draft['survey_json'], $live, $note ?? 'Veröffentlicht', $user);
            $this->drafts->delete($formKey);
            $result->merge($this->linter->lint($form['config'], $survey));

            ($this->audit)('form_published', $formKey, ['sha256' => hash('sha256', $draft['survey_json']), 'revision' => $revId]);

            return new ServiceResult($result, hash('sha256', $draft['survey_json']), $revId);
        });
    }

    /**
     * Throw the survey draft away. The live survey is not touched.
     *
     * @return bool false if there was no draft
     * @throws NotFoundException the form does not exist for this tenant
     */
    public function discardDraft(string $formKey, string $user): bool
    {
        $this->configs->find($formKey) ?? throw new NotFoundException("Formular '{$formKey}' nicht gefunden");

        $discarded = $this->drafts->delete($formKey);
        if ($discarded) {
            ($this->audit)('form_draft_discarded', $formKey, []);
        }
        return $discarded;
    }

    /**
     * Restore an earlier state: a survey revision becomes the live survey, a config revision becomes the live config.
     * Revisions of other forms or tenants are "not found".
     *
     * @throws NotFoundException
     */
    public function restoreRevision(string $formKey, int $revisionId, string $role, string $user): ServiceResult
    {
        $rev = $this->revisions->find($revisionId);
        if ($rev === null || $rev['form_key'] !== $formKey) {
            throw new NotFoundException('Revision nicht gefunden');
        }
        $note = "Wiederhergestellt aus Revision #{$revisionId}";

        if ($rev['kind'] === FormRevisionRepository::KIND_CONFIG) {
            $old = json_decode($rev['content'], true);
            if (!is_array($old)) {
                return ServiceResult::error('', 'Die Revision enthält keine gültige Konfiguration');
            }
            $current = $this->configs->find($formKey) ?? throw new NotFoundException("Formular '{$formKey}' nicht gefunden");
            return $this->saveConfig($formKey, $this->configValidator->submissionForRestore($old, $current['config'], $role), $role, $user, null, $note);
        }

        if ($rev['kind'] !== FormRevisionRepository::KIND_SURVEY) {
            return ServiceResult::error('', 'Dieser Revisionstyp kann nicht wiederhergestellt werden');
        }

        return $this->transaction(function () use ($formKey, $rev, $note, $user): ServiceResult {
            $form = $this->configs->find($formKey) ?? throw new NotFoundException("Formular '{$formKey}' nicht gefunden");
            $name = $this->surveyName($form['config']);
            if ($name === null || $rev['name'] !== $name) {
                return ServiceResult::error('form', 'Die Revision gehört zu einer anderen Survey-Datei als die aktuelle Konfiguration');
            }

            // Content was valid when it went live, but rules may have become stricter: check again.
            ['result' => $result, 'survey' => $survey] = $this->surveyValidator->validate($rev['content']);
            if (!$result->isValid() || $survey === null) {
                return new ServiceResult($result);
            }

            $live  = $this->resources->find(FormResourceRepository::KIND_SURVEY, $name, forUpdate: true);
            $revId = $this->putSurveyLive($formKey, $name, $rev['content'], $live, $note, $user);

            ($this->audit)('form_rolled_back', $formKey, ['to_revision' => $rev['id'], 'revision' => $revId]);

            $result->merge($this->linter->lint($form['config'], $survey));
            return new ServiceResult($result, hash('sha256', $rev['content']), $revId);
        });
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Replace the live survey, keeping history: the old live state is stored first if it is not
     * the newest revision yet (e.g. imported from files), then the new one.
     *
     * @param array{content: string, sha256: string}|null $live
     * @return int id of the revision of the new live state
     */
    private function putSurveyLive(string $formKey, string $name, string $content, ?array $live, string $note, string $user): int
    {
        if ($live !== null) {
            $this->ensureRevision($formKey, FormRevisionRepository::KIND_SURVEY, $name, $live['content'], 'Stand vor der Änderung', $user);
        }
        $this->resources->save(FormResourceRepository::KIND_SURVEY, $name, $content, $user);
        $revId = $this->revisions->add($formKey, FormRevisionRepository::KIND_SURVEY, $name, $content, $note, $user);
        $this->revisions->pruneOldest($formKey, FormRevisionRepository::KIND_SURVEY, self::KEEP_REVISIONS);

        return $revId;
    }

    /** Store $content as a revision unless it already is the newest one of that kind. */
    private function ensureRevision(string $formKey, string $kind, ?string $name, string $content, string $note, string $user): void
    {
        $latest = $this->revisions->latest($formKey, $kind);
        if ($latest === null || !hash_equals($latest['sha256'], hash('sha256', $content))) {
            $this->revisions->add($formKey, $kind, $name, $content, $note, $user);
        }
    }

    /** @param array<string,mixed> $config */
    private function surveyName(array $config): ?string
    {
        $name = $config['form'] ?? null;
        return is_string($name) && Identifiers::isValidResourceName($name) ? $name : null;
    }

    /**
     * Lint warnings of a config against the live survey (empty if none is stored in the database yet).
     *
     * @param array<string,mixed> $config
     */
    private function lintAgainstLiveSurvey(array $config): ValidationResult
    {
        $name = $this->surveyName($config);
        if ($name === null) {
            return new ValidationResult();
        }
        $live = $this->resources->find(FormResourceRepository::KIND_SURVEY, $name);
        $survey = $live !== null ? json_decode($live['content'], true) : null;

        return is_array($survey) ? $this->linter->lint($config, $survey) : new ValidationResult();
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function transaction(callable $fn): mixed
    {
        $this->db->begin_transaction();
        try {
            $result = $fn();
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
