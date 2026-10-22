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

    public function testFilterFieldsBelongToTheFilterGetForm(): void
    {
        foreach (['name="name"', 'name="email"', 'name="status"', 'name="f[<?= htmlspecialchars($col) ?>]"'] as $field) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($field, '/') . '\s+form="filter-form"/',
                $this->source,
                "the filter $field must reference the filter form"
            );
        }
        $this->assertStringContainsString('<form method="get" id="filter-form">', $this->source);
    }

    public function testColumnPickerCheckboxesBelongToTheColumnsGetForm(): void
    {
        $this->assertMatchesRegularExpression('/name="cols\[\]"\s+form="columns-form"/', $this->source);
        $this->assertStringContainsString('<form method="get" id="columns-form">', $this->source);
    }
}
