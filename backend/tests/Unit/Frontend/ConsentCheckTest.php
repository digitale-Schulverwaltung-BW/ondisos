<?php
declare(strict_types=1);

namespace Tests\Unit\Frontend;

use Frontend\Config\FormConfig;
use Frontend\Services\AnmeldungService;
use Frontend\Services\BackendApiClient;
use Frontend\Services\EmailService;
use Frontend\Utils\ConsentCheck;
use PHPUnit\Framework\TestCase;

class ConsentCheckTest extends TestCase
{
    private const SURVEYS = __DIR__ . '/../../../../frontend/surveys/';

    protected function setUp(): void
    {
        parent::setUp();
        $src = __DIR__ . '/../../../../frontend/src/';
        foreach (['Config/FormConfig', 'Services/BackendApiClient', 'Services/EmailService', 'Services/MessageService',
                  'Utils/ClientIp', 'Utils/ConsentCheck', 'Services/AnmeldungService'] as $file) {
            require_once $src . $file . '.php';
        }
    }

    /** @return array<string,mixed> */
    private function survey(array ...$elements): array
    {
        return ['pages' => [['name' => 'p1', 'elements' => $elements]]];
    }

    private function consent(string $name, array $extra = []): array
    {
        return $extra + ['type' => 'checkbox', 'name' => $name, 'isRequired' => true, 'choices' => [['value' => 'ja']]];
    }

    /** @return array<string,mixed> */
    private function file(string $name): array
    {
        return json_decode((string)file_get_contents(self::SURVEYS . $name), true, 512, JSON_THROW_ON_ERROR);
    }

    // --- required() ---------------------------------------------------------------------

    public function testFindsUnconditionalRequiredConsents(): void
    {
        $survey = $this->survey($this->consent('consent_Datenschutz'), ['type' => 'text', 'name' => 'Name', 'isRequired' => true]);

        $this->assertSame(['consent_Datenschutz'], ConsentCheck::required($survey));
    }

    public function testOptionalConsentIsNotRequired(): void
    {
        $survey = $this->survey($this->consent('consent_Newsletter', ['isRequired' => false]));

        $this->assertSame([], ConsentCheck::required($survey));
    }

    public function testConditionalConsentIsNotChecked(): void
    {
        $survey = $this->survey(
            $this->consent('consent_A', ['visibleIf' => "{x} = 'y'"]),
            $this->consent('consent_B', ['enableIf' => '{x} notempty']),
            $this->consent('consent_C', ['isRequired' => false, 'requiredIf' => '{x} = 1']),
            $this->consent('consent_D', ['visible' => false])
        );

        $this->assertSame([], ConsentCheck::required($survey));
    }

    public function testConsentInsidePanelCountsUnlessThePanelIsConditional(): void
    {
        $survey = ['pages' => [
            ['name' => 'p1', 'elements' => [
                ['type' => 'panel', 'name' => 'plain', 'elements' => [$this->consent('consent_Inner')]],
                ['type' => 'panel', 'name' => 'cond', 'visibleIf' => '{a} = 1', 'elements' => [$this->consent('consent_Hidden')]],
            ]],
            ['name' => 'p2', 'visibleIf' => '{b} = 1', 'elements' => [$this->consent('consent_OnHiddenPage')]],
        ]];

        $this->assertSame(['consent_Inner'], ConsentCheck::required($survey));
    }

    public function testConsentInDynamicPanelIsLeftOut(): void
    {
        $survey = $this->survey(['type' => 'paneldynamic', 'name' => 'kids', 'templateElements' => [$this->consent('consent_Kind')]]);

        $this->assertSame([], ConsentCheck::required($survey));
    }

    public function testShippedSurveysHaveTheExpectedRequiredConsents(): void
    {
        $this->assertSame(['consent_DatenschutzOk', 'consent_Vertrag'], ConsentCheck::required($this->file('bs.json')));
        // meister.json: the two certificate consents depend on the course type (visibleIf)
        $this->assertSame(['consent_DatenschutzOk'], ConsentCheck::required($this->file('meister.json')));
    }

    public function testPrivacyBuildingBlockIsARequiredConsent(): void
    {
        $block = json_decode((string)file_get_contents(__DIR__ . '/../../../resources/survey-templates/blocks/datenschutz.json'), true);

        $this->assertSame(['consent_DatenschutzOk'], ConsentCheck::required($this->survey($block)));
    }

    // --- isGiven() / missing() / given() ------------------------------------------------

    /** @return array<string,array{mixed,bool}> */
    public static function values(): array
    {
        return [
            'ticked checkbox'   => [['ja'], true],
            'empty checkbox'    => [[], false],
            'boolean true'      => [true, true],
            'boolean false'     => [false, false],
            'string "false"'    => ['false', false],
            'empty string'      => ['', false],
            'text answer'       => ['ja', true],
            'missing'           => [null, false],
            'list of empties'   => [[''], false],
        ];
    }

    /** @dataProvider values */
    public function testIsGiven(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, ConsentCheck::isGiven($value));
    }

    public function testMissingListsRequiredConsentsNotGiven(): void
    {
        $survey = $this->survey($this->consent('consent_A'), $this->consent('consent_B'));

        $this->assertSame(['consent_B'], ConsentCheck::missing($survey, ['consent_A' => ['ja'], 'consent_B' => []]));
        $this->assertSame([], ConsentCheck::missing($survey, ['consent_A' => ['ja'], 'consent_B' => true]));
    }

    public function testGivenListsAllTickedConsentFieldsSorted(): void
    {
        $data = ['consent_Z' => ['ja'], 'Name' => 'Max', 'consent_A' => true, 'consent_Nein' => []];

        $this->assertSame(['consent_A', 'consent_Z'], ConsentCheck::given($data));
    }

    // --- AnmeldungService ---------------------------------------------------------------

    /** @return array{AnmeldungService, object} */
    private function service(): array
    {
        FormConfig::load(['f' => ['form' => 'f.json', 'db' => true]]);
        $client = new class('http://backend.test/api', 'default', 's') extends BackendApiClient {
            /** @var list<array<string,mixed>> */
            public array $sent = [];

            public function submitAnmeldung(string $formKey, array $data, array $metadata, array $files = [], ?array $pdfConfig = null): array
            {
                $this->sent[] = $data;
                return ['success' => true, 'id' => 1];
            }
        };

        return [new AnmeldungService($client, new EmailService('from@test.de')), $client];
    }

    public function testSubmissionWithoutRequiredConsentIsRefused(): void
    {
        [$service, $client] = $this->service();

        $result = $service->processSubmission('f', ['Name' => 'Max'], [], [], $this->survey($this->consent('consent_Datenschutz')));

        $this->assertFalse($result['success']);
        $this->assertSame([], $client->sent, 'nothing reaches the backend');
    }

    public function testGivenConsentsAreRecordedAndTheAnswersRemoved(): void
    {
        [$service, $client] = $this->service();

        $result = $service->processSubmission(
            'f',
            ['Name' => 'Max', 'consent_Datenschutz' => ['ja'], ConsentCheck::RECORD_FIELD => ['forged']],
            [],
            [],
            $this->survey($this->consent('consent_Datenschutz'))
        );

        $this->assertTrue($result['success']);
        $sent = $client->sent[0];
        $this->assertSame(['consent_Datenschutz'], $sent[ConsentCheck::RECORD_FIELD], 'set by the server, not taken from the client');
        $this->assertArrayNotHasKey('consent_Datenschutz', $sent);
    }

    public function testWithoutSurveyTheCheckIsSkipped(): void
    {
        [$service, $client] = $this->service();

        $result = $service->processSubmission('f', ['Name' => 'Max']);

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey(ConsentCheck::RECORD_FIELD, $client->sent[0]);
    }
}
