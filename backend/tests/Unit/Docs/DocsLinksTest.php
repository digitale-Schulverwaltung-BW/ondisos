<?php
declare(strict_types=1);

namespace Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * The documentation lives in docs/ (plus a few files next to the code they describe). Moving files breaks links
 * silently; this fails when a relative Markdown link points nowhere, and keeps the repository root tidy.
 */
class DocsLinksTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../..';

    /** @return list<string> repo-relative paths of all Markdown files that are part of the project */
    private function markdownFiles(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = substr((string)$file->getPathname(), strlen(self::ROOT) + 1);
            if (!str_ends_with($path, '.md')) {
                continue;
            }
            if (preg_match('#(^|/)(vendor|node_modules|\.planning|\.claude|\.git|coverage|\.phpunit\.cache)/#', $path) === 1) {
                continue;
            }
            $files[] = $path;
        }
        sort($files);
        return $files;
    }

    public function testRelativeLinksPointToExistingFiles(): void
    {
        $broken = [];
        foreach ($this->markdownFiles() as $file) {
            $text = (string)file_get_contents(self::ROOT . '/' . $file);
            $text = preg_replace('/```.*?```/s', '', $text) ?? $text;   // code samples may contain pseudo links

            preg_match_all('/\]\(([^)\s#]+)(?:#[^)\s]*)?\)/', $text, $m);
            foreach ($m[1] as $target) {
                if (preg_match('#^([a-z][a-z0-9+.-]*:|/)#i', $target) === 1) {
                    continue; // URL, mailto, absolute
                }
                $resolved = self::ROOT . '/' . dirname($file) . '/' . $target;
                if (!file_exists($resolved)) {
                    $broken[] = "{$file} → {$target}";
                }
            }
        }

        $this->assertSame([], $broken, "broken documentation links:\n" . implode("\n", $broken));
    }

    public function testRepositoryRootKeepsOnlyReadmeAndClaudeMd(): void
    {
        $rootMd = array_map('basename', glob(self::ROOT . '/*.md') ?: []);
        sort($rootMd);

        $this->assertSame(['CLAUDE.md', 'README.md'], $rootMd, 'put documentation into docs/ (plans into docs/plans/)');
    }

    public function testTheDocsIndexListsEveryDocumentInDocs(): void
    {
        $index = (string)file_get_contents(self::ROOT . '/docs/README.md');
        $missing = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/docs', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $rel = substr((string)$file->getPathname(), strlen(self::ROOT . '/docs/'));
            if (str_ends_with($rel, '.md') && $rel !== 'README.md' && !str_contains($index, '(' . $rel . ')')) {
                $missing[] = $rel;
            }
        }
        $this->assertSame([], $missing, 'add these to docs/README.md');
    }
}
