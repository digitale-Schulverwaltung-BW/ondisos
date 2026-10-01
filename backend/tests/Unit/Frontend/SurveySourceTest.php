<?php
declare(strict_types=1);

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class SurveySourceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $base = __DIR__ . '/../../../../frontend/src/';
        require_once $base . 'Config/FormConfig.php';
        require_once $base . 'Services/BackendApiClient.php';
        require_once $base . 'Config/FormBundleCache.php';
        require_once $base . 'Config/FormConfigLoader.php';
        require_once $base . 'Utils/JsonEmbed.php';
        require_once $base . 'Config/SurveySource.php';

        \Frontend\Config\FormConfig::load([]);
        \Frontend\Config\FormConfigLoader::reset();

        $this->dir = sys_get_temp_dir() . '/ondisos-surveys-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        \Frontend\Config\FormConfigLoader::reset();
    }

    /** Load a form the way index.php does, with a fake backend answer. */
    private function load(array $config, ?string $survey, ?string $theme): void
    {
        $client = new class('http://x', 't', 's', $config, $survey, $theme) extends \Frontend\Services\BackendApiClient {
            public function __construct(string $u, string $t, string $s, private array $c, private ?string $sv, private ?string $th)
            {
                parent::__construct($u, $t, $s);
            }

            public function fetchFormBundle(string $formKey, string $tenantSlug, ?string $etag = null): array
            {
                return ['status' => 'ok', 'config' => $this->c, 'survey_json' => $this->sv, 'theme_json' => $this->th, 'etag' => null];
            }
        };
        $cache = new \Frontend\Config\FormBundleCache($this->dir . '/cache');
        $this->assertTrue(\Frontend\Config\FormConfigLoader::ensureWithSurvey('bs', $client, 't', $cache));
    }

    public function testBackendSurveyIsPreferredOverTheFile(): void
    {
        file_put_contents($this->dir . '/bs.json', '{"from":"file"}');
        $this->load(['form' => 'bs.json', 'theme' => 'survey_theme.json'], '{"from":"backend"}', '{"theme":"backend"}');

        $this->assertSame('{"from":"backend"}', \Frontend\Config\SurveySource::survey('bs', $this->dir));
        $this->assertSame('{"theme":"backend"}', \Frontend\Config\SurveySource::theme('bs', $this->dir));
    }

    public function testFallsBackToFilesWhenTheBackendHasNone(): void
    {
        file_put_contents($this->dir . '/bs.json', '{"from":"file"}');
        file_put_contents($this->dir . '/survey_theme.json', '{"theme":"file"}');
        $this->load(['form' => 'bs.json', 'theme' => 'survey_theme.json'], null, null);

        $this->assertSame('{"from":"file"}', \Frontend\Config\SurveySource::survey('bs', $this->dir));
        $this->assertSame('{"theme":"file"}', \Frontend\Config\SurveySource::theme('bs', $this->dir));
    }

    public function testMissingThemeIsEmptyObjectButMissingSurveyIsAnError(): void
    {
        $this->load(['form' => 'bs.json', 'theme' => 'gibtsnicht.json'], null, null);

        $this->assertSame('{}', \Frontend\Config\SurveySource::theme('bs', $this->dir));
        $this->expectException(\RuntimeException::class);
        \Frontend\Config\SurveySource::survey('bs', $this->dir);
    }

    /** @return array<string,array{0:string}> */
    public static function badNames(): array
    {
        return ['traversal' => ['../secret.json'], 'subdir' => ['a/b.json'], 'absolute' => ['/etc/passwd.json'], 'php' => ['x.php'],
                'hidden' => ['.hidden.json'], 'no ext' => ['bs'], 'newline' => ["bs.json\n"], 'null byte' => ["bs.json\0.php"]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badNames')]
    public function testFileFallbackRejectsNamesThatAreNotPlainFileNames(string $name): void
    {
        file_put_contents($this->dir . '/secret.json', '{"secret":1}');
        file_put_contents(dirname($this->dir) . '/secret.json', '{"outside":1}');
        $this->load(['form' => $name, 'theme' => $name], null, null);

        try {
            \Frontend\Config\SurveySource::survey('bs', $this->dir);
            $this->fail('accepted ' . json_encode($name));
        } catch (\RuntimeException) {
            $this->assertSame('{}', \Frontend\Config\SurveySource::theme('bs', $this->dir), 'bad theme name behaves like no theme');
        } finally {
            @unlink(dirname($this->dir) . '/secret.json');
        }
    }

    public function testResultIsSafeToEmbedInAScriptElement(): void
    {
        $this->load(['form' => 'bs.json', 'theme' => 't.json'], '{"pages":[{"elements":[{"type":"html","name":"h","html":"</script><script>alert(1)</script>"}]}],"o":{}}', '{"c":"<!--"}');

        $survey = \Frontend\Config\SurveySource::survey('bs', $this->dir);
        $theme  = \Frontend\Config\SurveySource::theme('bs', $this->dir);

        foreach ([$survey, $theme] as $out) {
            $this->assertStringNotContainsString('<', $out);
            $this->assertStringNotContainsString('>', $out);
        }
        $this->assertStringContainsString('"o":{}', $survey);
    }

    public function testInvalidJsonFromAnySourceIsAnError(): void
    {
        $this->load(['form' => 'bs.json', 'theme' => 't.json'], '{"broken": ', null);
        $this->expectException(\RuntimeException::class);
        \Frontend\Config\SurveySource::survey('bs', $this->dir);
    }

    public function testTheRealSurveyFilesCanBeServedUnchangedInMeaning(): void
    {
        $dir = __DIR__ . '/../../../../frontend/surveys';
        $this->load(['form' => 'bs.json', 'theme' => 'survey_theme.json'], null, null);

        $out = \Frontend\Config\SurveySource::survey('bs', $dir);

        $this->assertEquals(json_decode((string)file_get_contents($dir . '/bs.json')), json_decode($out));
        $this->assertEquals(json_decode((string)file_get_contents($dir . '/survey_theme.json')), json_decode(\Frontend\Config\SurveySource::theme('bs', $dir)));
        $this->assertStringNotContainsString('<', $out);
    }
}
