<?php
declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\AnmeldungStatus;
use PHPUnit\Framework\TestCase;

class AnmeldungStatusTest extends TestCase
{
    public function testLabelIsHumanReadableNotRawValue(): void
    {
        $this->assertSame('In Bearbeitung', AnmeldungStatus::IN_BEARBEITUNG->label());
        $this->assertSame('Neu', AnmeldungStatus::NEU->label());
    }

    public function testEveryStatusHasALabelDifferentFromItsKey(): void
    {
        foreach (AnmeldungStatus::cases() as $status) {
            $this->assertStringNotContainsString('missing', $status->label());
            $this->assertNotSame($status->value, $status->label());
        }
    }
}
