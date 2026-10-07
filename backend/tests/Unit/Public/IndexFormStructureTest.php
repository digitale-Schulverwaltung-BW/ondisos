<?php
declare(strict_types=1);

namespace Tests\Unit\Public;

use PHPUnit\Framework\TestCase;

/**
 * Guards the list page against nested <form> elements: the name filter once sat inside the bulk form (HTML ignores
 * the inner <form> tag), so Enter in the filter posted to bulk_actions.php ("Invalid action").
 */
class IndexFormStructureTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = (string) file_get_contents(__DIR__ . '/../../../public/index.php');
    }

    public function testFormTagsAreBalanced(): void
    {
        $this->assertSame(
            substr_count($this->source, '<form'),
            substr_count($this->source, '</form>'),
            'every <form> needs exactly one </form> (a stray one closes the bulk form early)'
        );
    }

    public function testNoFormIsNestedInsideTheBulkForm(): void
    {
        $start = strpos($this->source, 'id="bulkForm"');
        $this->assertNotFalse($start, 'bulk form not found');

        $end = strpos($this->source, '</form>', $start);
        $this->assertNotFalse($end);

        $inside = substr($this->source, $start, $end - $start);
        $this->assertStringNotContainsString('<form', $inside, 'forms must not be nested in the bulk form');
    }

    public function testFilterFieldsBelongToTheirOwnGetForms(): void
    {
        foreach (['name', 'email', 'status'] as $field) {
            $this->assertMatchesRegularExpression(
                '/name="' . $field . '"\s+form="filter-' . $field . '"/',
                $this->source,
                "the $field filter must reference its own form"
            );
        }
        $this->assertStringContainsString('id="filter-<?= $filterField ?>"', $this->source);
    }
}
