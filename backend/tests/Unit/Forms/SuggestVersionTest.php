<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Controllers\SurveyEditorController;
use PHPUnit\Framework\TestCase;

class SuggestVersionTest extends TestCase
{
    public function testIncrementsATrailingVersionNumber(): void
    {
        $this->assertSame('2026-01-v3', SurveyEditorController::suggestVersion('2026-01-v2'));
        $this->assertSame('v10', SurveyEditorController::suggestVersion('v9'));
        $this->assertSame('1.0.0-v2', SurveyEditorController::suggestVersion('1.0.0-v1'));
    }

    public function testOtherVersionsStartAFreshSeriesWithTheCurrentMonth(): void
    {
        $now = new \DateTimeImmutable('2026-09-15');
        $this->assertSame('2026-09-v1', SurveyEditorController::suggestVersion('', $now));
        $this->assertSame('2026-09-v1', SurveyEditorController::suggestVersion('demo', $now));
        $this->assertSame('2026-09-v1', SurveyEditorController::suggestVersion('1.0.0', $now));
        $this->assertSame('2026-09-v1', SurveyEditorController::suggestVersion('v2-final', $now));
    }
}
