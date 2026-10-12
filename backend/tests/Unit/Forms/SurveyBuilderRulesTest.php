<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\SurveyBuilderRules;
use App\Forms\SurveyValidator;
use PHPUnit\Framework\TestCase;

/**
 * The JavaScript tests of the survey builder read the server's rules from a committed fixture. It must equal what the
 * server enforces, otherwise the builder would be tested against rules that no longer exist.
 */
class SurveyBuilderRulesTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../js/fixtures/survey-rules.json';

    public function testFixtureEqualsTheRulesOfTheServer(): void
    {
        $fixture = json_decode((string)file_get_contents(self::FIXTURE), true, 16, JSON_THROW_ON_ERROR);

        $this->assertSame(
            SurveyBuilderRules::toArray(),
            $fixture,
            'Refresh with: php backend/tools/dump-survey-rules.php > backend/tests/js/fixtures/survey-rules.json'
        );
    }

    public function testRulesAreJsonEncodable(): void
    {
        $this->assertIsString(json_encode(SurveyBuilderRules::toArray(), JSON_THROW_ON_ERROR));
    }

    public function testReservedNamesAreExactlyThoseTheValidatorRefuses(): void
    {
        $validator = new SurveyValidator();
        foreach (SurveyBuilderRules::toArray()['reservedNames'] as $name) {
            $survey = json_encode(['pages' => [['elements' => [['type' => 'text', 'name' => $name]]]]], JSON_THROW_ON_ERROR);
            $this->assertFalse($validator->validate($survey)['result']->isValid(), "{$name} must be refused");
        }
    }
}
