<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../inc/survey_builder.php';

/**
 * The page side of the graphical survey builder: the configuration embedded as JSON, and the structure of form_survey.php
 * (what the script relies on: ids, the text area inside the JSON tab, files that exist).
 */
class SurveyBuilderPageTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private function file(string $path): string
    {
        return (string)file_get_contents(self::ROOT . '/' . $path);
    }

    public function testConfigCarriesRulesMessagesTakenNamesAndFindings(): void
    {
        $view = ['live' => ['fields' => ['Name', 'question3']]];
        $report = ['valid' => true, 'errors' => [], 'warnings' => [['path' => 'pages[0].elements[1].visibleIf', 'message' => 'm', 'line' => 7]]];

        $config = sb_config($view, $report);

        $this->assertSame(['Name', 'question3'], $config['taken']);
        $this->assertContains('website_url', $config['rules']['reservedNames']);
        $this->assertArrayHasKey('a', $config['rules']['htmlTags']);
        $this->assertSame('Grafisch', $config['messages']['tab']['graphic']);
        $this->assertSame(['pages', 0, 'elements', 1, 'visibleIf'], $config['findings'][0]['path']);
        $this->assertSame('graphic', $config['startTab']);
    }

    public function testWithoutAPublishedSurveyNoNamesAreTaken(): void
    {
        $this->assertSame([], sb_config(['live' => null], null)['taken']);
    }

    public function testAnInvalidReportStartsInTheJsonTabBecauseItsErrorsPointAtLines(): void
    {
        $this->assertSame('json', sb_config(['live' => null], ['valid' => false, 'errors' => [], 'warnings' => []])['startTab']);
        $this->assertSame('graphic', sb_config(['live' => null], ['valid' => true, 'errors' => [], 'warnings' => []])['startTab']);
        $this->assertSame('graphic', sb_config(['live' => null], null)['startTab']);
    }

    public function testEmbeddedJsonCannotLeaveItsScriptElement(): void
    {
        $json = sb_json(['findings' => [['message' => '</script><script>alert(1)</script><!-- & "\' ']]]);

        $this->assertStringNotContainsString('<', $json);
        $this->assertStringNotContainsString('>', $json);
        $this->assertStringNotContainsString('&', $json);
        $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame('</script><script>alert(1)</script><!-- & "\' ', $decoded['findings'][0]['message']);
    }

    public function testThePageIncludesTheTabsTheConfigAndTheScriptAsAModule(): void
    {
        $php = $this->file('public/form_survey.php');

        $this->assertStringContainsString("inc/survey_builder.php", $php);
        $this->assertStringContainsString("inc/survey_builder_tabs.php", $php);
        $this->assertStringContainsString('id="builder-config"', $php);
        $this->assertStringContainsString('sb_json($builderConfig)', $php);
        $this->assertStringContainsString('<script type="module" src="assets/survey-builder/app.js"></script>', $php);
        $this->assertStringContainsString('assets/survey-builder/builder.css', $php);
        // the tabs sit inside the one form that posts the text
        $this->assertGreaterThan(strpos($php, 'id="survey-form"'), strpos($php, 'inc/survey_builder_tabs.php'));
    }

    public function testTheTabsKeepTheTextAreaInsideTheJsonTabAndOfferTheBuilderRoot(): void
    {
        $tabs = $this->file('inc/survey_builder_tabs.php');

        foreach (['id="se-tab-graphic"', 'id="se-tab-json"', 'id="se-pane-graphic"', 'id="se-pane-json"', 'id="sb-root"', 'id="se-copy"'] as $needle) {
            $this->assertStringContainsString($needle, $tabs);
        }
        $json = substr($tabs, (int)strpos($tabs, 'id="se-pane-json"'));
        $this->assertStringContainsString('id="survey-text" name="survey_text"', $json, 'the text area that is posted stays in the JSON tab');
        $this->assertStringNotContainsString('id="survey-text"', substr($tabs, 0, (int)strpos($tabs, 'id="se-pane-json"')));
    }

    public function testNoScriptOfTheBuilderIsInline(): void
    {
        $tabs = $this->file('inc/survey_builder_tabs.php');
        $page = $this->file('public/form_survey.php');

        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*(?:src=|type="application\/json"))/', $tabs . $page, 'only external scripts and JSON data: the CSP allows nothing else');
        $this->assertDoesNotMatchRegularExpression('/\son(?:click|change|input|submit|load)\s*=/i', $tabs);
    }

    public function testEveryFileThePageLoadsExists(): void
    {
        foreach (['app.js', 'ui.js', 'ui-props.js', 'dom.js', 'text-bridge.js', 'model.js', 'registry.js', 'names.js', 'json-doc.js', 'builder.css'] as $file) {
            $this->assertFileExists(self::ROOT . '/public/assets/survey-builder/' . $file);
        }
        // every module imports only files that exist next to it
        foreach (glob(self::ROOT . '/public/assets/survey-builder/*.js') ?: [] as $js) {
            preg_match_all("/from '\.\/([a-z-]+\.js)'/", (string)file_get_contents($js), $m);
            foreach ($m[1] as $import) {
                $this->assertFileExists(dirname($js) . '/' . $import, basename($js) . ' imports ' . $import);
            }
        }
    }

    public function testEveryTextTheTabsAskForExistsInTheStandardMessages(): void
    {
        $messages = require self::ROOT . '/config/messages.php';
        $tabs = $this->file('inc/survey_builder_tabs.php');
        preg_match_all("/M::get\('builder\.([a-z_.]+)'/", $tabs, $m);

        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $key) {
            $value = $messages['builder'];
            foreach (explode('.', $key) as $part) {
                $this->assertArrayHasKey($part, $value, "builder.{$key}");
                $value = $value[$part];
            }
            $this->assertIsString($value);
        }
    }
}
