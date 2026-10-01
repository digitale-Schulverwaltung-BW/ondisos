<?php
declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Services\FormSeedService as Seed;

/**
 * @group integration
 */
class FormSeedServiceTest extends FormEditorTestCase
{
    private Seed $seed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed = new Seed($this->configs);
    }

    public function testSeedsTheWholeConfigTemplateForTheCurrentTenantOnly(): void
    {
        $forms = require __DIR__ . '/../../../../frontend/config/forms-config-dist.php';

        $result = $this->seed->seed($forms);

        foreach ($result as $key => $r) {
            $this->assertSame(Seed::STATUS_SEEDED, $r['status'], $key . ': ' . json_encode($r['validation']->errors()));
        }
        $this->assertEqualsCanonicalizing(array_keys($forms), $this->configs->listKeys());
        $this->asTenant($this->tenantB);
        $this->assertSame([], $this->configs->listKeys());
    }

    public function testNeverOverwritesExistingForms(): void
    {
        $this->configs->insert('bs', ['form' => 'bs.json', 'theme' => 't.json', 'version' => 'LIVE']);

        $r = $this->seed->seed(['bs' => ['form' => 'bs.json', 'theme' => 't.json', 'version' => 'SEED'], 'neu' => ['form' => 'neu.json', 'theme' => 't.json']]);

        $this->assertSame(Seed::STATUS_SKIPPED, $r['bs']['status']);
        $this->assertSame(Seed::STATUS_SEEDED, $r['neu']['status']);
        $this->assertSame('LIVE', $this->configs->find('bs')['config']['version']);
    }

    public function testInvalidEntriesAreReportedAndSkippedWithoutBlockingTheRest(): void
    {
        $r = $this->seed->seed([
            'gut'      => ['form' => 'gut.json', 'theme' => 't.json'],
            'kein_theme' => ['form' => 'x.json'],
            'bad_mail' => ['form' => 'm.json', 'theme' => 't.json', 'notify_email' => 'kein-mail'],
            'traversal' => ['form' => '../x.json', 'theme' => 't.json'],
            'Gross'    => ['form' => 'g.json', 'theme' => 't.json'],
            'a/b'      => ['form' => 'g.json', 'theme' => 't.json'],
        ]);

        $this->assertSame(Seed::STATUS_SEEDED, $r['gut']['status']);
        foreach (['kein_theme', 'bad_mail', 'traversal', 'Gross', 'a/b'] as $key) {
            $this->assertSame(Seed::STATUS_INVALID, $r[$key]['status'], $key);
            $this->assertNotEmpty($r[$key]['validation']->errors(), $key);
        }
        $this->assertSame(['gut'], $this->configs->listKeys());
    }

    public function testNonFormEntriesAreIgnored(): void
    {
        $r = $this->seed->seed([0 => ['x' => 1], 'text' => 'kein array']);
        $this->assertSame(Seed::STATUS_IGNORED, $r['0']['status']);
        $this->assertSame(Seed::STATUS_IGNORED, $r['text']['status']);
        $this->assertSame([], $this->configs->listKeys());
    }

    public function testSeedingIsIdempotent(): void
    {
        $forms = ['bs' => ['form' => 'bs.json', 'theme' => 't.json']];
        $this->seed->seed($forms);
        $again = $this->seed->seed($forms);
        $this->assertSame(Seed::STATUS_SKIPPED, $again['bs']['status']);
        $this->assertSame(1, $this->countRows('form_configs', $this->tenantA));
    }

    public function testSameFormKeyCanBeSeededForTwoTenants(): void
    {
        $this->seed->seed(['bs' => ['form' => 'bs.json', 'theme' => 't.json', 'version' => 'A']]);
        $this->asTenant($this->tenantB);
        $r = $this->seed->seed(['bs' => ['form' => 'bs.json', 'theme' => 't.json', 'version' => 'B']]);
        $this->assertSame(Seed::STATUS_SEEDED, $r['bs']['status']);
        $this->assertSame('B', $this->configs->find('bs')['config']['version']);
    }
}
