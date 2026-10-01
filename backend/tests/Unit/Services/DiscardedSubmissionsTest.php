<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use Frontend\Config\FormConfig;
use Frontend\Services\AnmeldungService;
use Frontend\Services\BackendApiClient;
use Frontend\Services\EmailService;
use PHPUnit\Framework\TestCase;

/**
 * A form with db=false and no valid notify_email would throw every submission away while the visitor
 * sees a success page. FormConfig::discardsSubmissions() detects it and AnmeldungService fails closed.
 */
class DiscardedSubmissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../../../frontend/src/Config/FormConfig.php';
        require_once __DIR__ . '/../../../../frontend/src/Services/BackendApiClient.php';
        require_once __DIR__ . '/../../../../frontend/src/Services/EmailService.php';
        require_once __DIR__ . '/../../../../frontend/src/Services/MessageService.php';
        require_once __DIR__ . '/../../../../frontend/src/Services/AnmeldungService.php';

        FormConfig::load([]);
    }

    /**
     * @param array<string,mixed> $config
     */
    private function load(array $config): void
    {
        FormConfig::load(['f' => ['form' => 'f.json', 'theme' => 't.json'] + $config]);
    }

    /**
     * @return array<string,array{array<string,mixed>,bool}>
     */
    public static function cases(): array
    {
        return [
            'db false, no recipient'            => [['db' => false], true],
            'db false, empty recipient'         => [['db' => false, 'notify_email' => ''], true],
            'db false, invalid recipient'       => [['db' => false, 'notify_email' => 'kein-mail'], true],
            'db false, one valid + one invalid' => [['db' => false, 'notify_email' => 'a@schule.de, kaputt'], true],
            'db false, valid recipient'         => [['db' => false, 'notify_email' => 'a@schule.de'], false],
            'db false, valid list'              => [['db' => false, 'notify_email' => 'a@schule.de, b@schule.de'], false],
            'db false, valid array'             => [['db' => false, 'notify_email' => ['a@schule.de', 'b@schule.de']], false],
            'db true, no recipient'             => [['db' => true], false],
            'db missing (defaults to true)'     => [[], false],
        ];
    }

    /**
     * @dataProvider cases
     * @param array<string,mixed> $config
     */
    public function testDiscardsSubmissionsMatchesTheConfiguration(array $config, bool $expected): void
    {
        $this->load($config);

        $this->assertSame($expected, FormConfig::discardsSubmissions('f'));
    }

    public function testUnknownFormIsNotReportedAsDiscarding(): void
    {
        $this->assertFalse(FormConfig::discardsSubmissions('gibtsnicht'));
    }

    // -------------------------------------------------------------------------------------

    /**
     * @return array{AnmeldungService, object, object} service, backend client, mailer (both record calls)
     */
    private function service(): array
    {
        $client = new class('http://backend.test/api', 'default', 's') extends BackendApiClient {
            public int $submits = 0;

            public function submitAnmeldung(string $formKey, array $data, array $metadata, array $files = [], ?array $pdfConfig = null): array
            {
                $this->submits++;
                return ['success' => true, 'id' => 7];
            }
        };
        $mailer = new class('from@test.de') extends EmailService {
            public int $mails = 0;

            public function sendNotification(string $to, string $formKey, array $formData, ?string $introTemplate = null): bool
            {
                $this->mails++;
                return true;
            }
        };

        return [new AnmeldungService($client, $mailer), $client, $mailer];
    }

    public function testDiscardingFormIsRefusedAndNothingIsSentOrStored(): void
    {
        $this->load(['db' => false]);
        [$service, $client, $mailer] = $this->service();

        $result = $service->processSubmission('f', ['Name' => 'Muster, Max']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('derzeit nicht verfügbar', $result['error']);
        $this->assertSame(0, $client->submits);
        $this->assertSame(0, $mailer->mails);
    }

    public function testMailOnlyFormStillWorksAndSendsTheMail(): void
    {
        $this->load(['db' => false, 'notify_email' => 'a@schule.de']);
        [$service, $client, $mailer] = $this->service();

        $result = $service->processSubmission('f', ['Name' => 'Muster, Max']);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $client->submits, 'db=false: nothing goes to the backend');
        $this->assertSame(1, $mailer->mails);
    }

    public function testStoredFormStillGoesToTheBackend(): void
    {
        $this->load(['db' => true]);
        [$service, $client] = $this->service();

        $result = $service->processSubmission('f', ['Name' => 'Muster, Max']);

        $this->assertTrue($result['success']);
        $this->assertSame(7, $result['id']);
        $this->assertSame(1, $client->submits);
    }
}
