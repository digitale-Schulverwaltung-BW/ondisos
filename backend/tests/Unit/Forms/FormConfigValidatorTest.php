<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\FormConfigSchema;
use App\Forms\FormConfigValidator;
use PHPUnit\Framework\TestCase;

class FormConfigValidatorTest extends TestCase
{
    private FormConfigValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new FormConfigValidator();
    }

    public function testEveryEntryOfTheConfigTemplateIsValid(): void
    {
        $forms = require __DIR__ . '/../../../../frontend/config/forms-config-dist.php';
        $this->assertNotEmpty($forms);

        foreach ($forms as $key => $config) {
            $result = $this->validator->validate($config);
            $this->assertSame([], $result->errors(), "form '{$key}' of forms-config-dist.php must pass");
        }
    }

    public function testSchemaListsOnlyKnownTypesAndRoles(): void
    {
        $types = [
            FormConfigSchema::TYPE_BOOL, FormConfigSchema::TYPE_STRING, FormConfigSchema::TYPE_TEXT,
            FormConfigSchema::TYPE_INT, FormConfigSchema::TYPE_EMAIL_LIST, FormConfigSchema::TYPE_NAME_LIST,
            FormConfigSchema::TYPE_FIELD_FILTER, FormConfigSchema::TYPE_DATE, FormConfigSchema::TYPE_TIME,
            FormConfigSchema::TYPE_SECTIONS, FormConfigSchema::TYPE_RESOURCE, FormConfigSchema::TYPE_PATH,
        ];
        foreach (FormConfigSchema::fields() as $path => $field) {
            $this->assertContains($field['type'], $types, $path);
            $this->assertNotEmpty($field['editableBy'], $path);
        }
    }

    public function testLogoAndFileNamesAreNotEditableByTenantAdmins(): void
    {
        $this->assertFalse(FormConfigSchema::isEditableBy('pdf.logo', FormConfigSchema::ROLE_TENANT));
        $this->assertFalse(FormConfigSchema::isEditableBy('form', FormConfigSchema::ROLE_TENANT));
        $this->assertFalse(FormConfigSchema::isEditableBy('theme', FormConfigSchema::ROLE_TENANT));
        $this->assertTrue(FormConfigSchema::isEditableBy('pdf.logo', FormConfigSchema::ROLE_PLATFORM));
        $this->assertTrue(FormConfigSchema::isEditableBy('notify_email', FormConfigSchema::ROLE_TENANT));
        $this->assertFalse(FormConfigSchema::isEditableBy('unknown.key', FormConfigSchema::ROLE_PLATFORM));
    }

    // ---- apply(): merging ---------------------------------------------------------------------

    public function testApplySetsSubmittedFieldsAndKeepsEverythingElse(): void
    {
        $existing = [
            'form' => 'bs.json', 'theme' => 'survey_theme.json', 'version' => 'v1',
            'custom_future_key' => ['x' => 1],
            'pdf' => ['enabled' => true, 'footer_text' => 'alt', 'secret_extra' => 'bleibt'],
        ];

        ['config' => $config, 'result' => $result] = $this->validator->apply($existing, [
            'version' => 'v2',
            'pdf' => ['footer_text' => 'neu'],
        ], FormConfigSchema::ROLE_TENANT);

        $this->assertTrue($result->isValid());
        $this->assertSame('v2', $config['version']);
        $this->assertSame('neu', $config['pdf']['footer_text']);
        $this->assertTrue($config['pdf']['enabled'], 'absent field stays unchanged');
        $this->assertSame('bleibt', $config['pdf']['secret_extra'], 'unknown keys survive');
        $this->assertSame(['x' => 1], $config['custom_future_key']);
        $this->assertSame('bs.json', $config['form']);
    }

    public function testApplyDoesNotMutateTheInput(): void
    {
        $existing = ['form' => 'a.json', 'theme' => 'b.json', 'version' => '1'];
        $copy     = $existing;
        $this->validator->apply($existing, ['version' => '2'], FormConfigSchema::ROLE_PLATFORM);
        $this->assertSame($copy, $existing);
    }

    public function testEmptyValueRemovesTheKeyAndEmptyParentShell(): void
    {
        $existing = ['pdf' => ['footer_text' => 'x'], 'notify_email' => ['a@b.de']];

        ['config' => $config] = $this->validator->apply($existing, [
            'pdf' => ['footer_text' => '  '],
            'notify_email' => '',
        ], FormConfigSchema::ROLE_TENANT);

        $this->assertArrayNotHasKey('pdf', $config);
        $this->assertArrayNotHasKey('notify_email', $config);
    }

    public function testBoolFieldsAreAlwaysStoredAsBool(): void
    {
        ['config' => $config] = $this->validator->apply([], [
            'db' => '0', 'pdf' => ['enabled' => 'on', 'required' => ''],
        ], FormConfigSchema::ROLE_TENANT);

        $this->assertFalse($config['db']);
        $this->assertTrue($config['pdf']['enabled']);
        $this->assertFalse($config['pdf']['required']);
    }

    // ---- roles --------------------------------------------------------------------------------

    public function testTenantAdminCannotChangeLogoOrFiles(): void
    {
        $existing = ['form' => 'bs.json', 'theme' => 't.json', 'pdf' => ['logo' => 'logo.png']];

        ['config' => $config, 'result' => $result] = $this->validator->apply($existing, [
            'form' => 'other.json',
            'pdf' => ['logo' => '/etc/passwd'],
        ], FormConfigSchema::ROLE_TENANT);

        $this->assertTrue($result->hasErrorAt('form'));
        $this->assertTrue($result->hasErrorAt('pdf.logo'));
        $this->assertSame('bs.json', $config['form']);
        $this->assertSame('logo.png', $config['pdf']['logo']);
    }

    public function testTenantAdminMayResubmitUnchangedRestrictedFields(): void
    {
        // The admin form posts every field; restricted ones come back unchanged.
        $existing = ['form' => 'bs.json', 'theme' => 't.json', 'pdf' => ['logo' => false]];

        ['result' => $result] = $this->validator->apply($existing, [
            'form' => 'bs.json', 'theme' => 't.json', 'pdf' => ['logo' => ''],
        ], FormConfigSchema::ROLE_TENANT);

        $this->assertTrue($result->isValid());
    }

    public function testPlatformAdminMayChangeLogo(): void
    {
        ['config' => $config, 'result' => $result] = $this->validator->apply(
            [],
            ['pdf' => ['logo' => 'logo.png']],
            FormConfigSchema::ROLE_PLATFORM
        );
        $this->assertTrue($result->isValid());
        $this->assertSame('logo.png', $config['pdf']['logo']);
    }

    /** @return array<string,array{0:string}> */
    public static function badLogoPaths(): array
    {
        return [
            'traversal'  => ['../../.env'], 'hidden traversal' => ['a/../../b.png'], 'url' => ['http://evil.example/x.png'],
            'phar'       => ['phar://x.phar/a'], 'null byte' => ["a.png\0.txt"], 'space' => ['my logo.png'],
            'too long'   => [str_repeat('a', 256)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badLogoPaths')]
    public function testBadLogoPathsAreRejectedEvenForPlatformAdmins(string $path): void
    {
        ['result' => $result] = $this->validator->apply([], ['pdf' => ['logo' => $path]], FormConfigSchema::ROLE_PLATFORM);
        $this->assertTrue($result->hasErrorAt('pdf.logo'), $path);
    }

    public function testExplicitNullRemovesAnEditableKeyButCannotRemoveARestrictedOne(): void
    {
        $existing = ['form' => 'bs.json', 'theme' => 't.json', 'pdf' => ['enabled' => true, 'logo' => 'logo.png']];

        ['config' => $config, 'result' => $result] = $this->validator->apply($existing, ['pdf' => ['enabled' => null, 'logo' => null]], FormConfigSchema::ROLE_TENANT);

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasErrorAt('pdf.logo'));
        $this->assertSame('logo.png', $config['pdf']['logo']);
        $this->assertArrayNotHasKey('enabled', $config['pdf']);
    }

    // ---- field types --------------------------------------------------------------------------

    public function testNotifyEmailAcceptsStringOrListAndNormalizesToList(): void
    {
        ['config' => $a] = $this->validator->apply([], ['notify_email' => 'a@b.de, c@d.de;e@f.de'], FormConfigSchema::ROLE_TENANT);
        ['config' => $b] = $this->validator->apply([], ['notify_email' => [' a@b.de ', 'a@b.de']], FormConfigSchema::ROLE_TENANT);

        $this->assertSame(['a@b.de', 'c@d.de', 'e@f.de'], $a['notify_email']);
        $this->assertSame(['a@b.de'], $b['notify_email']);
    }

    public function testInvalidEmailIsRejected(): void
    {
        ['result' => $r1] = $this->validator->apply([], ['notify_email' => 'kein-mail'], FormConfigSchema::ROLE_TENANT);
        ['result' => $r2] = $this->validator->apply([], ['notify_email' => "a@b.de\nBcc: evil@x.de"], FormConfigSchema::ROLE_TENANT);
        ['result' => $r3] = $this->validator->apply([], ['notify_email' => array_map(fn ($i) => "u{$i}@x.de", range(1, 21))], FormConfigSchema::ROLE_TENANT);

        $this->assertTrue($r1->hasErrorAt('notify_email'));
        $this->assertTrue($r2->hasErrorAt('notify_email'), 'header injection attempt');
        $this->assertTrue($r3->hasErrorAt('notify_email'), 'too many recipients');
    }

    public function testTokenLifetimeRange(): void
    {
        foreach ([['59', false], ['60', true], ['1800', true], ['86400', true], ['86401', false], ['abc', false], ['1.5', false]] as [$v, $ok]) {
            ['result' => $r] = $this->validator->apply([], ['pdf' => ['token_lifetime' => $v]], FormConfigSchema::ROLE_TENANT);
            $this->assertSame($ok, $r->isValid(), "token_lifetime={$v}");
        }
    }

    public function testDateAndTimeFormats(): void
    {
        foreach ([['2026-09-01', true], ['2026-02-30', false], ['01.09.2026', false], ['2026-9-1', false]] as [$v, $ok]) {
            ['result' => $r] = $this->validator->apply([], ['ical' => ['event_date' => $v]], FormConfigSchema::ROLE_TENANT);
            $this->assertSame($ok, $r->isValid(), "date {$v}");
        }
        foreach ([['08:00', true], ['23:59', true], ['24:00', false], ['8:00', false], ['08:60', false]] as [$v, $ok]) {
            ['result' => $r] = $this->validator->apply([], ['ical' => ['event_time_start' => $v]], FormConfigSchema::ROLE_TENANT);
            $this->assertSame($ok, $r->isValid(), "time {$v}");
        }
    }

    public function testIncludeFieldsAcceptsAllOrList(): void
    {
        ['config' => $a] = $this->validator->apply([], ['pdf' => ['include_fields' => 'all']], FormConfigSchema::ROLE_TENANT);
        ['config' => $b] = $this->validator->apply([], ['pdf' => ['include_fields' => "Vorname\nNachname\n"]], FormConfigSchema::ROLE_TENANT);

        $this->assertSame('all', $a['pdf']['include_fields']);
        $this->assertSame(['Vorname', 'Nachname'], $b['pdf']['include_fields']);
    }

    public function testSectionsDropEmptyRowsAndRejectGarbage(): void
    {
        ['config' => $config, 'result' => $ok] = $this->validator->apply([], ['pdf' => ['pre_sections' => [
            ['title' => 'Hinweis', 'content' => "Zeile 1\nZeile 2"],
            ['title' => '', 'content' => ''],
        ]]], FormConfigSchema::ROLE_TENANT);
        ['result' => $bad] = $this->validator->apply([], ['pdf' => ['pre_sections' => 'text']], FormConfigSchema::ROLE_TENANT);

        $this->assertTrue($ok->isValid());
        $this->assertCount(1, $config['pdf']['pre_sections']);
        $this->assertFalse($bad->isValid());
    }

    public function testControlCharactersAreRejectedInSingleLineStrings(): void
    {
        ['result' => $r] = $this->validator->apply([], ['version' => "v1\nX-Evil: 1"], FormConfigSchema::ROLE_TENANT);
        $this->assertTrue($r->hasErrorAt('version'));
    }

    public function testTooLongStringIsRejected(): void
    {
        ['result' => $r] = $this->validator->apply([], ['version' => str_repeat('x', 51)], FormConfigSchema::ROLE_TENANT);
        $this->assertTrue($r->hasErrorAt('version'));
    }

    public function testWrongTypesAreRejectedNotCoerced(): void
    {
        ['result' => $r] = $this->validator->apply([], ['version' => ['a'], 'db' => 'maybe', 'notify_email' => 5], FormConfigSchema::ROLE_TENANT);
        $this->assertTrue($r->hasErrorAt('version'));
        $this->assertTrue($r->hasErrorAt('db'));
        $this->assertTrue($r->hasErrorAt('notify_email'));
    }

    // ---- validate(): complete config ---------------------------------------------------------

    public function testValidateRequiresFormAndTheme(): void
    {
        $result = $this->validator->validate(['version' => '1']);
        $this->assertTrue($result->hasErrorAt('form'));
        $this->assertTrue($result->hasErrorAt('theme'));
    }

    public function testValidateRejectsTraversalInFormName(): void
    {
        $result = $this->validator->validate(['form' => '../../etc/passwd', 'theme' => 'survey_theme.json']);
        $this->assertTrue($result->hasErrorAt('form'));
    }
}
