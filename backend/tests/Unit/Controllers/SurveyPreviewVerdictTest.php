<?php
declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\SurveyPreviewController;
use App\Forms\SurveyValidator;
use PHPUnit\Framework\TestCase;

/**
 * The live preview (AP4a) renders an unsaved text only if the server says "ok": the same validator as for stored surveys.
 */
class SurveyPreviewVerdictTest extends TestCase
{
    private function verdict(string $text): array
    {
        return SurveyPreviewController::verdict(new SurveyValidator(), $text);
    }

    public function testAValidSurveyMayBeRendered(): void
    {
        $text = (string)json_encode(['pages' => [['name' => 'p1', 'elements' => [['type' => 'text', 'name' => 'vorname']]]]]);

        $this->assertSame(['status' => 'ok', 'errors' => []], $this->verdict($text));
    }

    public function testScriptInAnHtmlElementIsRefused(): void
    {
        $text = (string)json_encode(['elements' => [['type' => 'html', 'name' => 'h', 'html' => '<img src=x onerror="alert(1)">']]]);

        $verdict = $this->verdict($text);

        $this->assertSame('invalid', $verdict['status']);
        $this->assertNotEmpty($verdict['errors']);
    }

    public function testBrokenJsonIsRefused(): void
    {
        $this->assertSame('invalid', $this->verdict('{"pages": [')['status']);
        $this->assertSame('invalid', $this->verdict('')['status']);
    }

    public function testErrorsAreCappedAndCarryOnlyPathAndMessage(): void
    {
        $elements = [];
        for ($i = 0; $i < 20; $i++) {
            $elements[] = ['type' => 'text', 'name' => 'dup'];
        }
        $verdict = $this->verdict((string)json_encode(['elements' => $elements]));

        $this->assertSame('invalid', $verdict['status']);
        $this->assertLessThanOrEqual(SurveyPreviewController::MAX_REPORTED, count($verdict['errors']));
        foreach ($verdict['errors'] as $error) {
            $this->assertSame(['path', 'message'], array_keys($error));
        }
    }
}
