<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\FormDeliveryService as D;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FormDeliveryEtagTest extends TestCase
{
    private const ETAG = 'abc123def456';

    /** @return array<string,array{0:?string,1:bool}> */
    public static function headers(): array
    {
        return [
            'exact quoted'        => ['"abc123def456"', true],
            'unquoted'            => ['abc123def456', true],
            'weak'                => ['W/"abc123def456"', true],
            'apache gzip suffix'  => ['"abc123def456-gzip"', true],
            'br suffix'           => ['"abc123def456-br"', true],
            'list with match'     => ['"nope", "abc123def456"', true],
            'wildcard'            => ['*', true],
            'other etag'          => ['"zzz"', false],
            'prefix of etag'      => ['"abc123"', false],
            'etag plus junk'      => ['"abc123def456x"', false],
            'empty'               => ['', false],
            'only quotes'         => ['""', false],
            'null'                => [null, false],
        ];
    }

    #[DataProvider('headers')]
    public function testEtagMatching(?string $header, bool $expected): void
    {
        $this->assertSame($expected, D::etagMatches($header, self::ETAG));
    }
}
