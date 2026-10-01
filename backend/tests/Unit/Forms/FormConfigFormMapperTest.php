<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\FormConfigFormMapper as Map;
use App\Forms\FormConfigSchema as S;
use App\Forms\FormConfigValidator;
use PHPUnit\Framework\TestCase;

class FormConfigFormMapperTest extends TestCase
{
    public function testEverySchemaFieldAndGroupHasAGermanLabelInTheMessages(): void
    {
        $messages = require __DIR__ . '/../../../config/messages.php';

        foreach (S::fields() as $path => $field) {
            $node = $messages['forms']['fields'];
            foreach (explode('.', $path) as $segment) {
                $node = $node[$segment] ?? [];
            }
            $this->assertNotEmpty($node['label'] ?? null, "label for {$path}");
            $this->assertNotEmpty($messages['forms']['groups'][$field['group']] ?? null, "group {$field['group']} of {$path}");
        }
    }

    public function testFromPostReadsOnlySchemaFieldsAndIgnoresEverythingElse(): void
    {
        $submitted = Map::fromPost([
            'version'  => '2',
            'evil'     => 'x',
            '__proto__' => ['polluted' => 1],
            'pdf'      => ['enabled' => '1', 'unknown_key' => 'y'],
        ]);

        $this->assertSame(['version' => '2', 'pdf' => ['enabled' => '1']], $submitted);
    }

    public function testOnlyPostedFieldsAppearSoRestrictedDisabledInputsStayUntouched(): void
    {
        $this->assertSame([], Map::fromPost([]));
        $this->assertSame(['version' => 'x'], Map::fromPost(['version' => 'x']));
    }

    public function testFieldFilterModeAll(): void
    {
        $s = Map::fromPost(['pdf' => ['include_fields__mode' => 'all', 'include_fields' => ['a', 'b']]]);
        $this->assertSame('all', $s['pdf']['include_fields']);
    }

    public function testFieldFilterModeListKeepsTheNames(): void
    {
        $s = Map::fromPost(['pdf' => ['include_fields__mode' => 'list', 'include_fields' => ['a', '', 'b']]]);
        $this->assertSame(['a', '', 'b'], $s['pdf']['include_fields'], 'empty hidden marker is dropped later by the validator');
        $s = Map::fromPost(['pdf' => ['include_fields__mode' => 'list', 'include_fields' => "a\nb"]]);
        $this->assertSame("a\nb", $s['pdf']['include_fields']);
    }

    public function testSectionsRowsAreReindexedAndNonStringListEntriesDropped(): void
    {
        $s = Map::fromPost(['pdf' => ['pre_sections' => [3 => ['title' => 'A', 'content' => 'x'], 7 => ['title' => '', 'content' => '']]]]);
        $this->assertSame([['title' => 'A', 'content' => 'x'], ['title' => '', 'content' => '']], $s['pdf']['pre_sections']);

        $s = Map::fromPost(['prefill_fields' => ['a', ['nested'], 5, 'b']]);
        $this->assertSame(['a', 'b'], $s['prefill_fields']);
    }

    public function testFormValuesDefaultsAndLegacyShapes(): void
    {
        $v = Map::formValues([
            'notify_email' => 'a@b.de, c@d.de',
            'pdf' => ['logo' => false, 'token_lifetime' => 1800, 'include_fields' => 'all', 'exclude_fields' => ['x']],
        ]);

        $this->assertTrue($v['db'], 'db defaults to true like the frontend does');
        $this->assertFalse($v['pdf.enabled']);
        $this->assertSame(['a@b.de', 'c@d.de'], $v['notify_email']);
        $this->assertSame('', $v['pdf.logo'], 'legacy false = no logo');
        $this->assertSame('1800', $v['pdf.token_lifetime']);
        $this->assertSame('all', $v['pdf.include_fields']);
        $this->assertSame(['x'], $v['pdf.exclude_fields']);
        $this->assertSame([], $v['pdf.pre_sections']);
    }

    public function testOverlayShowsSubmittedValuesWithoutValidatingThem(): void
    {
        $config = ['form' => 'bs.json', 'version' => '1', 'pdf' => ['enabled' => true, 'footer_text' => 'alt']];
        $merged = Map::overlay($config, ['notify_email' => 'kein-mail', 'pdf' => ['footer_text' => 'neu']]);

        $this->assertSame('kein-mail', $merged['notify_email']);
        $this->assertSame('neu', $merged['pdf']['footer_text']);
        $this->assertTrue($merged['pdf']['enabled']);
        $this->assertSame('bs.json', $merged['form']);
        $this->assertSame(['kein-mail'], Map::formValues($merged)['notify_email']);
    }

    /**
     * Pretend the browser posts back exactly what the form shows (every field, checkboxes as 0/1, lists as text).
     *
     * @param array<string,mixed> $values result of formValues()
     * @return array<string,mixed> cfg[...] array
     */
    private function browserPost(array $values): array
    {
        $cfg = [];
        foreach (S::fields() as $path => $field) {
            $value = $values[$path];
            $post = match ($field['type']) {
                S::TYPE_BOOL       => $value ? '1' : '0',
                S::TYPE_EMAIL_LIST => implode("\n", $value),
                S::TYPE_FIELD_FILTER => $value,
                default            => $value,
            };
            $segments = explode('.', $path);
            $last = array_pop($segments);
            $node = &$cfg;
            foreach ($segments as $segment) {
                $node[$segment] ??= [];
                $node = &$node[$segment];
            }
            if ($field['type'] === S::TYPE_FIELD_FILTER) {
                $node[$last . Map::MODE_SUFFIX] = $value === 'all' ? 'all' : 'list';
                $node[$last] = $value === 'all' ? [] : $value;
            } else {
                $node[$last] = $post;
            }
            unset($node);
        }
        return $cfg;
    }

    public function testSavingAnUntouchedFormChangesNothingSemantically(): void
    {
        $validator = new FormConfigValidator();
        $forms = require __DIR__ . '/../../../../frontend/config/forms-config-dist.php';

        foreach ($forms as $key => $config) {
            $post = $this->browserPost(Map::formValues($config));
            ['config' => $saved, 'result' => $result] = $validator->apply($config, Map::fromPost($post), S::ROLE_PLATFORM);

            $this->assertSame([], $result->errors(), "form '{$key}' must survive a round trip through the HTML form");

            // The only intended difference: e-mail lists become real lists.
            if (isset($config['notify_email']) && is_string($config['notify_email'])) {
                $config['notify_email'] = array_values(array_filter(array_map('trim', explode(',', $config['notify_email']))));
                if ($config['notify_email'] === []) {
                    unset($config['notify_email']); // '' = nobody to notify = key absent
                }
            }
            // "No logo" (false) and empty lists mean the same as an absent key; the editor stores the absent form
            // (PdfTemplateRenderer, templates/pdf/base.php and DataFormatter treat both alike).
            foreach (['logo', 'pre_sections', 'post_sections'] as $emptyKey) {
                if (isset($config['pdf'][$emptyKey]) && ($config['pdf'][$emptyKey] === false || $config['pdf'][$emptyKey] === [])) {
                    unset($config['pdf'][$emptyKey]);
                }
            }
            // Compare only what the original had (checkboxes may add explicit false values).
            $this->assertEquals($config, array_intersect_key_recursive($saved, $config), "form '{$key}' lost or changed data");
        }
    }

    public function testRoundTripAsTenantAdminKeepsRestrictedFields(): void
    {
        $validator = new FormConfigValidator();
        $config = ['form' => 'bs.json', 'theme' => 'survey_theme.json', 'version' => '1', 'pdf' => ['enabled' => true, 'logo' => 'logo.png']];

        $post = $this->browserPost(Map::formValues($config));
        // Disabled inputs are not posted by browsers:
        unset($post['form'], $post['theme'], $post['pdf']['logo']);

        ['config' => $saved, 'result' => $result] = $validator->apply($config, Map::fromPost($post), S::ROLE_TENANT);

        $this->assertTrue($result->isValid(), json_encode($result->errors()));
        $this->assertSame('bs.json', $saved['form']);
        $this->assertSame('logo.png', $saved['pdf']['logo']);
    }
}

/**
 * @param array<string,mixed> $a
 * @param array<string,mixed> $mask
 * @return array<string,mixed>
 */
function array_intersect_key_recursive(array $a, array $mask): array
{
    $out = [];
    foreach ($mask as $k => $v) {
        if (!array_key_exists($k, $a)) {
            continue;
        }
        $out[$k] = is_array($v) && is_array($a[$k]) && !array_is_list($v) ? array_intersect_key_recursive($a[$k], $v) : $a[$k];
    }
    return $out;
}
