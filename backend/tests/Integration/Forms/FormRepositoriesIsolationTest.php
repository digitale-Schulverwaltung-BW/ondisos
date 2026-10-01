<?php
declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Config\TenantContext;
use App\Forms\ConflictException;
use App\Forms\NotFoundException;
use App\Repositories\FormResourceRepository as Res;
use App\Repositories\FormRevisionRepository as Rev;

/**
 * Tenant isolation of the form-editor repositories: one school must never see, change or delete
 * another school's configs, surveys, drafts or history — even when both use the same names.
 *
 * @group integration
 */
class FormRepositoriesIsolationTest extends FormEditorTestCase
{
    // ---- form_configs -----------------------------------------------------------------------

    public function testSameFormKeyCanExistInBothTenantsIndependently(): void
    {
        $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 't.json', 'version' => 'A']);
        $this->asTenant($this->tenantB);
        $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 't.json', 'version' => 'B']);

        $this->assertSame('B', $this->configs->find('bs')['config']['version']);
        $this->asTenant($this->tenantA);
        $this->assertSame('A', $this->configs->find('bs')['config']['version']);
    }

    public function testConfigOfOtherTenantIsInvisibleAndUntouchable(): void
    {
        $this->configs->insert('geheim', ['form' => 'g.json', 'theme' => 't.json', 'notify_email' => ['a@a.de']]);

        $this->asTenant($this->tenantB);
        $this->assertNull($this->configs->find('geheim'));
        $this->assertSame([], $this->configs->listKeys());
        $this->assertFalse($this->configs->delete('geheim'), 'delete must affect nothing');
        $this->assertSame(0, $this->configs->countSubmissions('geheim'));
        $this->expectException(NotFoundException::class);
        try {
            $this->configs->update('geheim', ['form' => 'x.json'], null);
        } finally {
            $this->asTenant($this->tenantA);
            $this->assertSame(['a@a.de'], $this->configs->find('geheim')['config']['notify_email'], 'unchanged');
        }
    }

    public function testDuplicateInsertInSameTenantConflicts(): void
    {
        $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 't.json']);
        $this->expectException(ConflictException::class);
        $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 't.json']);
    }

    public function testStaleVersionTokenIsRejected(): void
    {
        $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 't.json', 'version' => '1']);
        $token = $this->configs->find('bs')['sha256'];

        $this->configs->update('bs', ['form' => 'bs.json', 'theme' => 't.json', 'version' => '2'], $token);

        $this->expectException(ConflictException::class);
        $this->configs->update('bs', ['form' => 'bs.json', 'theme' => 't.json', 'version' => '3'], $token);
    }

    public function testSubmissionsAreCountedPerTenantAndFormIncludingTrash(): void
    {
        foreach ([[$this->tenantA, 'bs', 0], [$this->tenantA, 'bs', 1], [$this->tenantA, 'andere', 0], [$this->tenantB, 'bs', 0]] as [$t, $form, $deleted]) {
            $stmt = $this->db->prepare("INSERT INTO anmeldungen (tenant_id, formular, data, deleted) VALUES (?, ?, '{}', ?)");
            $stmt->bind_param('isi', $t, $form, $deleted);
            $stmt->execute();
        }
        $this->assertSame(2, $this->configs->countSubmissions('bs'));
        $this->asTenant($this->tenantB);
        $this->assertSame(1, $this->configs->countSubmissions('bs'));
    }

    // ---- form_resources ---------------------------------------------------------------------

    public function testResourcesWithSameNameDoNotCollideAcrossTenants(): void
    {
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', '{"a":1}', 'u');
        $this->asTenant($this->tenantB);
        $this->assertNull($this->resources->find(Res::KIND_SURVEY, 'bs.json'));
        $this->resources->save(Res::KIND_SURVEY, 'bs.json', '{"b":2}', 'u');

        $this->assertSame('{"b":2}', $this->resources->find(Res::KIND_SURVEY, 'bs.json')['content']);
        $this->asTenant($this->tenantA);
        $this->assertSame('{"a":1}', $this->resources->find(Res::KIND_SURVEY, 'bs.json')['content']);
    }

    public function testDeletingAResourceNeverTouchesOtherTenants(): void
    {
        $this->resources->save(Res::KIND_THEME, 'survey_theme.json', '{}', 'u');
        $this->asTenant($this->tenantB);
        $this->assertFalse($this->resources->delete(Res::KIND_THEME, 'survey_theme.json'));
        $this->assertSame([], $this->resources->listNames(Res::KIND_THEME));
        $this->asTenant($this->tenantA);
        $this->assertCount(1, $this->resources->listNames(Res::KIND_THEME));
    }

    public function testSurveyAndThemeKindsAreSeparateNamespaces(): void
    {
        $this->resources->save(Res::KIND_SURVEY, 'x.json', '{"s":1}', 'u');
        $this->resources->save(Res::KIND_THEME, 'x.json', '{"t":1}', 'u');
        $this->assertSame('{"s":1}', $this->resources->find(Res::KIND_SURVEY, 'x.json')['content']);
        $this->assertSame('{"t":1}', $this->resources->find(Res::KIND_THEME, 'x.json')['content']);
    }

    public function testResourceSaveRejectsBadNamesAndKinds(): void
    {
        foreach ([['survey', '../x.json'], ['survey', 'x.php'], ['survey', "x.json\n"], ['hack', 'x.json']] as [$kind, $name]) {
            try {
                $this->resources->save($kind, $name, '{}', 'u');
                $this->fail("accepted {$kind}/{$name}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testResourceHashMismatchIsAConflict(): void
    {
        $sha = $this->resources->save(Res::KIND_SURVEY, 'x.json', '{"v":1}', 'u');
        $this->resources->save(Res::KIND_SURVEY, 'x.json', '{"v":2}', 'u', $sha);
        $this->expectException(ConflictException::class);
        $this->resources->save(Res::KIND_SURVEY, 'x.json', '{"v":3}', 'u', $sha);
    }

    // ---- form_drafts ------------------------------------------------------------------------

    public function testDraftsAreIsolatedPerTenant(): void
    {
        $this->drafts->save('bs', '{"draft":"A"}', null, 'a');
        $this->asTenant($this->tenantB);
        $this->assertNull($this->drafts->find('bs'));
        $this->assertFalse($this->drafts->delete('bs'));
        $this->drafts->save('bs', '{"draft":"B"}', null, 'b');

        $this->asTenant($this->tenantA);
        $this->assertSame('{"draft":"A"}', $this->drafts->find('bs')['survey_json']);
    }

    public function testSavingADraftAgainReplacesIt(): void
    {
        $this->drafts->save('bs', '{"v":1}', null, 'a');
        $this->drafts->save('bs', '{"v":2}', 'abc', 'a');
        $this->assertSame('{"v":2}', $this->drafts->find('bs')['survey_json']);
        $this->assertSame('abc', $this->drafts->find('bs')['based_on_sha']);
        $this->assertSame(1, $this->countRows('form_drafts', $this->tenantA));
    }

    // ---- form_revisions ---------------------------------------------------------------------

    public function testRevisionOfOtherTenantIsNotFoundAndLoggedAsIdor(): void
    {
        $id = $this->revisions->add('bs', Rev::KIND_SURVEY, 'bs.json', '{"secret":1}', 'n', 'a');

        $this->asTenant($this->tenantB);
        $logBefore = $this->auditLogSize();
        $this->assertNull($this->revisions->find($id));
        $this->assertSame([], $this->revisions->list('bs'));
        $this->assertNull($this->revisions->latest('bs', Rev::KIND_SURVEY));
        $this->assertGreaterThan($logBefore, $this->auditLogSize(), 'idor_attempt must be written to the audit log');

        $this->asTenant($this->tenantA);
        $this->assertSame('{"secret":1}', $this->revisions->find($id)['content']);
    }

    public function testUnknownRevisionIdIsNotAnIdorAttempt(): void
    {
        $logBefore = $this->auditLogSize();
        $this->assertNull($this->revisions->find(2147483000));
        $this->assertSame($logBefore, $this->auditLogSize());
    }

    public function testPruningKeepsNewestAndOnlyInOwnTenantAndKind(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->revisions->add('bs', Rev::KIND_SURVEY, 'bs.json', "{\"v\":$i}", null, 'a');
        }
        $this->revisions->add('bs', Rev::KIND_CONFIG, null, '{}', null, 'a');
        $this->asTenant($this->tenantB);
        for ($i = 1; $i <= 5; $i++) {
            $this->revisions->add('bs', Rev::KIND_SURVEY, 'bs.json', "{\"b\":$i}", null, 'b');
        }

        $this->asTenant($this->tenantA);
        $removed = $this->revisions->pruneOldest('bs', Rev::KIND_SURVEY, 2);

        $this->assertSame(3, $removed);
        $list = $this->revisions->list('bs', Rev::KIND_SURVEY);
        $this->assertCount(2, $list);
        $this->assertSame('{"v":5}', $this->revisions->find($list[0]['id'])['content'], 'newest kept');
        $this->assertCount(1, $this->revisions->list('bs', Rev::KIND_CONFIG), 'other kind untouched');
        $this->asTenant($this->tenantB);
        $this->assertCount(5, $this->revisions->list('bs', Rev::KIND_SURVEY), 'other tenant untouched');
    }

    // ---- no tenant, no access ---------------------------------------------------------------

    public function testAllTenantsModeAndUninitializedContextCannotUseTheRepositories(): void
    {
        $calls = [
            fn () => $this->configs->find('bs'),
            fn () => $this->configs->listKeys(),
            fn () => $this->configs->insert('bs', []),
            fn () => $this->resources->find(Res::KIND_SURVEY, 'a.json'),
            fn () => $this->resources->save(Res::KIND_SURVEY, 'a.json', '{}', 'u'),
            fn () => $this->drafts->find('bs'),
            fn () => $this->drafts->save('bs', '{}', null, 'u'),
            fn () => $this->revisions->list('bs'),
            fn () => $this->revisions->add('bs', Rev::KIND_CONFIG, null, '{}', null, 'u'),
            fn () => $this->revisions->find(1),
        ];

        foreach (['all' => fn () => TenantContext::initAllTenants(), 'none' => fn () => TenantContext::reset()] as $label => $mode) {
            $mode();
            foreach ($calls as $i => $call) {
                try {
                    $call();
                    $this->fail("call #{$i} worked without a tenant ({$label})");
                } catch (\RuntimeException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    private function auditLogSize(): int
    {
        $file = __DIR__ . '/../../../logs/audit.log';
        clearstatcache(true, $file);
        return is_file($file) ? (int)filesize($file) : 0;
    }
}
