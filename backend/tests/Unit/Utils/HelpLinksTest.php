<?php
declare(strict_types=1);

namespace Tests\Unit\Utils;

use App\Config\Version;
use App\Utils\HelpLinks;
use PHPUnit\Framework\TestCase;

class HelpLinksTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../..';

    public function testDefaultBaseUrlIsPinnedToInstalledVersion(): void
    {
        $this->assertSame(
            'https://github.com/digitale-Schulverwaltung-BW/ondisos/blob/v' . Version::CURRENT . '/',
            HelpLinks::baseUrl()
        );
    }

    public function testConfiguredBaseUrlIsUsedWithVersionPlaceholder(): void
    {
        $this->assertSame('https://doku.example.org/ondisos/' . Version::CURRENT . '/', HelpLinks::baseUrl('https://doku.example.org/ondisos/{version}'));
    }

    public function testUnusableBaseUrlFallsBackToDefault(): void
    {
        $this->assertSame(HelpLinks::baseUrl(), HelpLinks::baseUrl('javascript:alert(1)'));
        $this->assertSame(HelpLinks::baseUrl(), HelpLinks::baseUrl('   '));
    }

    public function testPageWithoutTopicsGetsDocumentationIndex(): void
    {
        $links = HelpLinks::forPage('/admin/unbekannt.php', 'https://doku.example.org/');
        $this->assertCount(1, $links);
        $this->assertSame('https://doku.example.org/docs/README.md', $links[0]['url']);
    }

    public function testPageScriptPathIsReducedToBasename(): void
    {
        $this->assertSame(HelpLinks::forPage('index.php'), HelpLinks::forPage('/backend/public/index.php'));
    }

    public function testEveryTargetExistsInDocsIncludingAnchor(): void
    {
        $problems = [];
        foreach (HelpLinks::topics() as $page => $topics) {
            $this->assertNotEmpty($topics, $page);
            foreach ($topics as $topic) {
                [$file, $anchor] = array_pad(explode('#', $topic['path'], 2), 2, null);
                $full = self::ROOT . '/' . $file;
                if (!is_file($full)) {
                    $problems[] = "$page: $file fehlt";
                    continue;
                }
                if ($anchor !== null && !in_array($anchor, $this->anchors((string)file_get_contents($full)), true)) {
                    $problems[] = "$page: Anker #$anchor fehlt in $file";
                }
            }
        }
        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function testEveryNavigationPageHasHelp(): void
    {
        foreach (['index.php', 'detail.php', 'trash.php', 'dashboard.php', 'forms.php', 'form_edit.php', 'tenants.php'] as $page) {
            $this->assertArrayHasKey($page, HelpLinks::topics());
        }
    }

    public function testVersionMatchesPluginReadmeAndBadge(): void
    {
        $v = Version::CURRENT;
        $plugin = (string)file_get_contents(self::ROOT . '/wordpress-plugin/ondisos.php');
        $this->assertMatchesRegularExpression('/^ \* Version:\s*' . preg_quote($v, '/') . '\s*$/m', $plugin, 'Plugin-Header');
        $this->assertStringContainsString("'ONDISOS_PLUGIN_VERSION', '$v'", $plugin);
        $this->assertMatchesRegularExpression('/^Stable tag:\s*' . preg_quote($v, '/') . '\s*$/m', (string)file_get_contents(self::ROOT . '/wordpress-plugin/readme.txt'));
        $this->assertStringContainsString("Version-$v-blue", (string)file_get_contents(self::ROOT . '/README.md'));
    }

    /** @return list<string> GitHub-style heading anchors of a Markdown document */
    private function anchors(string $markdown): array
    {
        $markdown = preg_replace('/```.*?```/s', '', $markdown) ?? $markdown;
        preg_match_all('/^#{1,6}\s+(.+?)\s*$/m', $markdown, $m);
        $anchors = [];
        foreach ($m[1] as $heading) {
            $slug = mb_strtolower(trim($heading), 'UTF-8');
            $slug = preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $slug) ?? $slug;
            $anchors[] = str_replace(' ', '-', $slug);
        }
        return $anchors;
    }
}
