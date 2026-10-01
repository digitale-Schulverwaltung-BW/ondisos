<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Cli\CliArgs;
use PHPUnit\Framework\TestCase;

class CliArgsTest extends TestCase
{
    public function testOptionsFlagsAndPositionals(): void
    {
        $a = new CliArgs(['--tenant=schule-a', '--overwrite', '/tmp/surveys', '--dry-run']);

        $this->assertSame('schule-a', $a->value('tenant'));
        $this->assertTrue($a->flag('overwrite'));
        $this->assertTrue($a->flag('dry-run'));
        $this->assertFalse($a->flag('help'));
        $this->assertSame(['/tmp/surveys'], $a->positional);
    }

    public function testDashMeansStdinAndIsPositional(): void
    {
        $a = new CliArgs(['-']);
        $this->assertSame(['-'], $a->positional);
        $this->assertSame([], $a->options);
    }

    public function testValueContainingEqualsSignKeepsTheRest(): void
    {
        $this->assertSame('a=b', (new CliArgs(['--x=a=b']))->value('x'));
    }

    public function testBareFlagHasNoValueAndEmptyValueIsNull(): void
    {
        $a = new CliArgs(['--tenant', '--other=']);
        $this->assertNull($a->value('tenant'));
        $this->assertNull($a->value('other'));
        $this->assertFalse((new CliArgs(['--tenant=x']))->flag('tenant'), 'a value is not a flag');
    }

    public function testDoubleDashEndsOptionParsing(): void
    {
        $a = new CliArgs(['--', '--not-an-option', 'file']);
        $this->assertSame(['--not-an-option', 'file'], $a->positional);
        $this->assertSame([], $a->options);
    }

    public function testUnknownOptionsAreReported(): void
    {
        $a = new CliArgs(['--tenant=x', '--overwite', '--dry-run']);
        $this->assertSame(['overwite'], $a->unknownOptions(['tenant', 'overwrite', 'dry-run']));
    }
}
