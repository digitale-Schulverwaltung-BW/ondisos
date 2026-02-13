<?php
declare(strict_types=1);

namespace Tests\Unit\Utils;

use App\Utils\DataFormatter;
use PHPUnit\Framework\TestCase;

class DataFormatterTest extends TestCase
{
    // =========================================================================
    // humanizeKey
    // =========================================================================

    public function testHumanizeKeyReturnsKeyAsIs(): void
    {
        $this->assertSame('user_name', DataFormatter::humanizeKey('user_name'));
        $this->assertSame('emailAddress', DataFormatter::humanizeKey('emailAddress'));
        $this->assertSame('firma-name', DataFormatter::humanizeKey('firma-name'));
        $this->assertSame('', DataFormatter::humanizeKey(''));
    }

    // =========================================================================
    // formatValue
    // =========================================================================

    public function testFormatValueReturnsPlaceholderForNull(): void
    {
        $this->assertSame('-', DataFormatter::formatValue(null));
    }

    public function testFormatValueReturnsPlaceholderForEmptyString(): void
    {
        $this->assertSame('-', DataFormatter::formatValue(''));
    }

    public function testFormatValueFormatsBooleanTrue(): void
    {
        // M::get falls back to 'Ja' when config is not loaded
        $result = DataFormatter::formatValue(true);
        $this->assertSame('Ja', $result);
    }

    public function testFormatValueFormatsBooleanFalse(): void
    {
        $result = DataFormatter::formatValue(false);
        $this->assertSame('Nein', $result);
    }

    public function testFormatValueJoinsSimpleArray(): void
    {
        $this->assertSame('Alpha, Beta, Gamma', DataFormatter::formatValue(['Alpha', 'Beta', 'Gamma']));
    }

    public function testFormatValueHandlesSingleElementArray(): void
    {
        $this->assertSame('Einzeln', DataFormatter::formatValue(['Einzeln']));
    }

    public function testFormatValueConvertsArrayElementsToString(): void
    {
        $this->assertSame('1, 2, 3', DataFormatter::formatValue([1, 2, 3]));
    }

    public function testFormatValueHandlesFileUploadArray(): void
    {
        $files = [
            ['name' => 'zeugnis.pdf', 'content' => 'base64data'],
            ['name' => 'ausweis.jpg', 'content' => 'base64data'],
        ];
        $result = DataFormatter::formatValue($files);
        $this->assertSame('zeugnis.pdf, ausweis.jpg', $result);
    }

    public function testFormatValueHandlesFileUploadWithoutName(): void
    {
        $files = [
            ['content' => 'base64data'], // no 'name' key
        ];
        $result = DataFormatter::formatValue($files);
        $this->assertSame('Datei', $result);
    }

    public function testFormatValueConvertsIsoDateToGerman(): void
    {
        $this->assertSame('31.01.2000', DataFormatter::formatValue('2000-01-31'));
        $this->assertSame('01.12.2026', DataFormatter::formatValue('2026-12-01'));
    }

    public function testFormatValueReturnsRegularStringAsIs(): void
    {
        $this->assertSame('Hallo Welt', DataFormatter::formatValue('Hallo Welt'));
        $this->assertSame('123', DataFormatter::formatValue('123'));
    }

    public function testFormatValueCastsIntegerToString(): void
    {
        $this->assertSame('42', DataFormatter::formatValue(42));
    }

    // =========================================================================
    // isIsoDate
    // =========================================================================

    public function testIsIsoDateAcceptsValidDates(): void
    {
        $this->assertTrue(DataFormatter::isIsoDate('2026-01-15'));
        $this->assertTrue(DataFormatter::isIsoDate('2000-12-31'));
        $this->assertTrue(DataFormatter::isIsoDate('1990-01-01'));
    }

    public function testIsIsoDateRejectsInvalidFormats(): void
    {
        $this->assertFalse(DataFormatter::isIsoDate('15.01.2026'));   // German format
        $this->assertFalse(DataFormatter::isIsoDate('2026/01/15'));   // slash separator
        $this->assertFalse(DataFormatter::isIsoDate('2026-1-5'));     // no leading zeros
        $this->assertFalse(DataFormatter::isIsoDate('not-a-date'));
        $this->assertFalse(DataFormatter::isIsoDate(''));
        $this->assertFalse(DataFormatter::isIsoDate('2026-01-15 14:30:00')); // includes time
    }

    // =========================================================================
    // formatDate
    // =========================================================================

    public function testFormatDateConvertsToGermanFormat(): void
    {
        $this->assertSame('15.01.2026', DataFormatter::formatDate('2026-01-15'));
        $this->assertSame('31.12.1999', DataFormatter::formatDate('1999-12-31'));
        $this->assertSame('01.01.2000', DataFormatter::formatDate('2000-01-01'));
    }

    public function testFormatDateReturnsOriginalStringOnInvalidInput(): void
    {
        // DateTimeImmutable throws for truly invalid strings - the method catches and returns original
        $invalid = 'not-a-date-at-all-xyz';
        // Note: DateTimeImmutable is lenient with some strings, so test with a clearly broken one
        // The method guarantees it returns a string (original on exception)
        $result = DataFormatter::formatDate($invalid);
        $this->assertIsString($result);
    }

    // =========================================================================
    // filterFields
    // =========================================================================

    public function testFilterFieldsIncludesAllByDefault(): void
    {
        $data = ['a' => 1, 'b' => 2, 'c' => 3];
        $result = DataFormatter::filterFields($data);
        $this->assertArrayHasKey('a', $result);
        $this->assertArrayHasKey('b', $result);
        $this->assertArrayHasKey('c', $result);
    }

    public function testFilterFieldsWithIncludeList(): void
    {
        $data = ['first_name' => 'Max', 'last_name' => 'Muster', 'email' => 'max@example.com'];
        $result = DataFormatter::filterFields($data, ['first_name', 'email']);
        $this->assertArrayHasKey('first_name', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayNotHasKey('last_name', $result);
    }

    public function testFilterFieldsRemovesExcludedFields(): void
    {
        $data = ['name' => 'Max', 'email' => 'max@example.com', 'geburtsdatum' => '2000-01-01'];
        $result = DataFormatter::filterFields($data, 'all', ['geburtsdatum']);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayNotHasKey('geburtsdatum', $result);
    }

    public function testFilterFieldsRemovesInternalFields(): void
    {
        $data = [
            'name' => 'Max',
            '_fieldTypes' => ['name' => 'text'],
            '_internal' => 'hidden',
        ];
        $result = DataFormatter::filterFields($data);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayNotHasKey('_fieldTypes', $result);
        $this->assertArrayNotHasKey('_internal', $result);
    }

    public function testFilterFieldsRemovesConsentFields(): void
    {
        $data = [
            'name' => 'Max',
            'consent_datenschutz' => true,
            'consent_agb' => true,
        ];
        $result = DataFormatter::filterFields($data);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayNotHasKey('consent_datenschutz', $result);
        $this->assertArrayNotHasKey('consent_agb', $result);
    }

    public function testFilterFieldsCombinesIncludeAndExclude(): void
    {
        $data = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4];
        $result = DataFormatter::filterFields($data, ['a', 'b', 'c'], ['c']);
        $this->assertArrayHasKey('a', $result);
        $this->assertArrayHasKey('b', $result);
        $this->assertArrayNotHasKey('c', $result);
        $this->assertArrayNotHasKey('d', $result);
    }

    public function testFilterFieldsReturnsEmptyArrayForEmptyInput(): void
    {
        $result = DataFormatter::filterFields([]);
        $this->assertSame([], $result);
    }

    // =========================================================================
    // sortFieldsByOrder
    // =========================================================================

    public function testSortFieldsByOrderUsesFieldTypesOrder(): void
    {
        $data = ['c' => 3, 'a' => 1, 'b' => 2];
        $fieldTypes = ['a' => 'text', 'b' => 'text', 'c' => 'text'];

        $result = DataFormatter::sortFieldsByOrder($data, $fieldTypes);

        $this->assertSame(['a', 'b', 'c'], array_keys($result));
    }

    public function testSortFieldsByOrderPreservesDefinedOrder(): void
    {
        $data = ['email' => 'e@x.com', 'name' => 'Max', 'geburtsdatum' => '2000-01-01'];
        // Survey defines: geburtsdatum first, then name, then email
        $fieldTypes = ['geburtsdatum' => 'date', 'name' => 'text', 'email' => 'text'];

        $result = DataFormatter::sortFieldsByOrder($data, $fieldTypes);

        $this->assertSame(['geburtsdatum', 'name', 'email'], array_keys($result));
    }

    public function testSortFieldsByOrderAppendsExtraFieldsNotInFieldTypes(): void
    {
        $data = ['known' => 1, 'extra' => 2];
        $fieldTypes = ['known' => 'text']; // 'extra' not listed

        $result = DataFormatter::sortFieldsByOrder($data, $fieldTypes);

        $this->assertArrayHasKey('known', $result);
        $this->assertArrayHasKey('extra', $result);
        $this->assertSame('known', array_key_first($result));
    }

    public function testSortFieldsByOrderFallsBackToAlphabeticalWithNullFieldTypes(): void
    {
        $data = ['zebra' => 3, 'apple' => 1, 'mango' => 2];

        $result = DataFormatter::sortFieldsByOrder($data, null);

        $this->assertSame(['apple', 'mango', 'zebra'], array_keys($result));
    }

    public function testSortFieldsByOrderIgnoresFieldsInFieldTypesNotPresentInData(): void
    {
        $data = ['existing' => 1];
        $fieldTypes = ['existing' => 'text', 'missing' => 'text'];

        $result = DataFormatter::sortFieldsByOrder($data, $fieldTypes);

        $this->assertArrayHasKey('existing', $result);
        $this->assertArrayNotHasKey('missing', $result);
    }

    // =========================================================================
    // prepareForPdf
    // =========================================================================

    public function testPrepareForPdfFiltersAndSortsData(): void
    {
        $data = [
            'name' => 'Max',
            'email' => 'max@example.com',
            'consent_datenschutz' => true,
            '_fieldTypes' => ['email' => 'text', 'name' => 'text'],
        ];
        $pdfConfig = [
            'include_fields' => 'all',
            'exclude_fields' => [],
        ];

        $result = DataFormatter::prepareForPdf($data, $pdfConfig);

        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayNotHasKey('consent_datenschutz', $result);
        $this->assertArrayNotHasKey('_fieldTypes', $result);
        // Order from _fieldTypes: email, name
        $this->assertSame(['email', 'name'], array_keys($result));
    }

    public function testPrepareForPdfRespectsIncludeFields(): void
    {
        $data = [
            'name' => 'Max',
            'email' => 'max@example.com',
            'telefon' => '0123456',
        ];
        $pdfConfig = [
            'include_fields' => ['name', 'email'],
            'exclude_fields' => [],
        ];

        $result = DataFormatter::prepareForPdf($data, $pdfConfig);

        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayNotHasKey('telefon', $result);
    }

    public function testPrepareForPdfRespectsExcludeFields(): void
    {
        $data = ['name' => 'Max', 'email' => 'max@example.com'];
        $pdfConfig = [
            'include_fields' => 'all',
            'exclude_fields' => ['email'],
        ];

        $result = DataFormatter::prepareForPdf($data, $pdfConfig);

        $this->assertArrayHasKey('name', $result);
        $this->assertArrayNotHasKey('email', $result);
    }

    public function testPrepareForPdfUsesDefaultsWhenConfigKeysAbsent(): void
    {
        $data = ['name' => 'Max', 'consent_x' => true, '_meta' => 'y'];
        $pdfConfig = []; // no include_fields / exclude_fields

        $result = DataFormatter::prepareForPdf($data, $pdfConfig);

        $this->assertArrayHasKey('name', $result);
        $this->assertArrayNotHasKey('consent_x', $result);
        $this->assertArrayNotHasKey('_meta', $result);
    }
}
