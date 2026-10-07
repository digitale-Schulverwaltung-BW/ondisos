<?php
declare(strict_types=1);

namespace Tests\Unit\Utils;

use App\Utils\SafeRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SafeRedirectTest extends TestCase
{
    #[DataProvider('localTargets')]
    public function testLocalTargetsPassThrough(string $url): void
    {
        $this->assertSame($url, SafeRedirect::local($url));
    }

    public static function localTargets(): array
    {
        return [
            ['index.php'],
            ['detail.php?id=12'],
            ['index.php?form=anmeldung%20bk'],
        ];
    }

    #[DataProvider('foreignTargets')]
    public function testForeignTargetsFallBackToDefault(string $url): void
    {
        $this->assertSame('index.php', SafeRedirect::local($url));
    }

    public static function foreignTargets(): array
    {
        return [
            ['https://evil.example/'],
            ['//evil.example/'],
            ['/\\evil.example'],
            ['\\\\evil.example'],
            ['/index.php'],
            ['javascript:alert(1)'],
            ['../index.php'],
            ['sub/index.php'],
            ['index.php#x'],
            ["index.php?a=1\r\nSet-Cookie: x=y"],
            ['index.php '],
            ["index.php\n"],
            ['evil.example'],
            [''],
        ];
    }

    public function testNullAndCustomDefault(): void
    {
        $this->assertSame('index.php', SafeRedirect::local(null));
        $this->assertSame('trash.php', SafeRedirect::local('http://x', 'trash.php'));
    }
}
