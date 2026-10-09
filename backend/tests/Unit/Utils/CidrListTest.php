<?php

declare(strict_types=1);

namespace Tests\Unit\Utils;

use App\Utils\CidrList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CidrListTest extends TestCase
{
    public function testEmptyListContainsNothingAndIsEmpty(): void
    {
        $list = CidrList::parse('');
        $this->assertTrue($list->isEmpty());
        $this->assertFalse($list->contains('10.0.0.1'));
    }

    public function testSeparatorsCommaSemicolonAndLineBreak(): void
    {
        $list = CidrList::parse("10.0.0.0/8, 192.168.1.5;\n2001:db8::/32\r\n  203.0.113.7 ");
        $this->assertSame(['10.0.0.0/8', '192.168.1.5', '2001:db8::/32', '203.0.113.7'], $list->entries());
        $this->assertSame([], $list->invalid());
    }

    public function testInvalidEntriesAreReportedNotDropped(): void
    {
        $list = CidrList::parse('10.0.0.0/33, abc, 10.0.0.1/x, 2001:db8::/129, 999.1.1.1, 10.0.0.0/8');
        $this->assertSame(['10.0.0.0/8'], $list->entries());
        $this->assertSame(['10.0.0.0/33', 'abc', '10.0.0.1/x', '2001:db8::/129', '999.1.1.1'], $list->invalid());
    }

    public function testDuplicatesAreRemoved(): void
    {
        $this->assertSame(['10.0.0.1'], CidrList::parse('10.0.0.1, 10.0.0.1')->entries());
    }

    public function testSingleIpMatchesOnlyItself(): void
    {
        $list = CidrList::parse('192.168.1.5');
        $this->assertTrue($list->contains('192.168.1.5'));
        $this->assertFalse($list->contains('192.168.1.6'));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function prefixProvider(): array
    {
        return [
            'zero prefix matches all v4' => ['0.0.0.0/0', '8.8.8.8', true],
            'slash 32 exact' => ['10.1.2.3/32', '10.1.2.3', true],
            'slash 32 other' => ['10.1.2.3/32', '10.1.2.4', false],
            'byte boundary in' => ['10.10.0.0/16', '10.10.255.1', true],
            'byte boundary out' => ['10.10.0.0/16', '10.11.0.1', false],
            'partial bits in' => ['10.10.0.0/23', '10.10.1.200', true],
            'partial bits out' => ['10.10.0.0/23', '10.10.2.1', false],
            'slash 25 edge in' => ['10.10.0.0/25', '10.10.0.127', true],
            'slash 25 edge out' => ['10.10.0.0/25', '10.10.0.128', false],
            'v6 prefix in' => ['2001:db8::/32', '2001:db8:1::1', true],
            'v6 prefix out' => ['2001:db8::/32', '2001:db9::1', false],
            'v6 slash 128' => ['::1/128', '::1', true],
            'v6 zero prefix' => ['::/0', '2001:db8::1', true],
            'v4 range never matches v6' => ['10.0.0.0/8', '2001:db8::1', false],
            'v6 range never matches v4' => ['2001:db8::/32', '10.0.0.1', false],
        ];
    }

    #[DataProvider('prefixProvider')]
    public function testPrefixLengths(string $entry, string $ip, bool $expected): void
    {
        $this->assertSame($expected, CidrList::parse($entry)->contains($ip));
    }

    public function testIpv4MappedAddressIsTreatedAsIpv4(): void
    {
        $list = CidrList::parse('10.0.0.0/8');
        $this->assertTrue($list->contains('::ffff:10.1.2.3'));
        $this->assertFalse($list->contains('::ffff:11.1.2.3'));
    }

    public function testIpv4MappedEntryBecomesIpv4Range(): void
    {
        $list = CidrList::parse('::ffff:10.0.0.0/104');
        $this->assertSame(['10.0.0.0/8'], $list->entries());
        $this->assertTrue($list->contains('10.9.9.9'));
    }

    public function testIpv4MappedEntryWithShortPrefixIsInvalid(): void
    {
        $list = CidrList::parse('::ffff:10.0.0.0/64');
        $this->assertSame([], $list->entries());
        $this->assertSame(['::ffff:10.0.0.0/64'], $list->invalid());
    }

    public function testInvalidIpIsNeverContained(): void
    {
        $list = CidrList::parse('0.0.0.0/0');
        $this->assertFalse($list->contains('unknown'));
        $this->assertFalse($list->contains(''));
    }

    public function testNormalize(): void
    {
        $this->assertSame('10.0.0.1', CidrList::normalize('::ffff:10.0.0.1'));
        $this->assertSame('2001:db8::1', CidrList::normalize('2001:0db8:0:0:0:0:0:1'));
        $this->assertNull(CidrList::normalize('nope'));
    }
}
