<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Config\TenantContext;
use App\Repositories\SignatureHandoffRepository;
use App\Services\SignatureHandoffService;
use PHPUnit\Framework\TestCase;

/**
 * In-memory stand-in for the table; scoped by TenantContext like the real repository.
 */
final class InMemorySignatureHandoffRepository extends SignatureHandoffRepository
{
    /** @var array<int, array<string, array<string, mixed>>> */
    public array $rows = [];

    public function __construct()
    {
    }

    public function insert(array $row): void
    {
        $this->rows[TenantContext::getTenantId()][$row['id']] = $row + ['png' => null, 'status' => 'open'];
    }

    public function find(string $id): ?array
    {
        return $this->rows[TenantContext::getTenantId()][$id] ?? null;
    }

    public function countOpen(int $now): int
    {
        return count(array_filter($this->rows[TenantContext::getTenantId()] ?? [], fn($r) => $r['expires_at'] > $now));
    }

    public function markScanned(string $id, int $now): bool
    {
        $row = $this->find($id);
        if ($row === null || $row['status'] !== 'open' || $row['expires_at'] <= $now) {
            return false;
        }
        $this->rows[TenantContext::getTenantId()][$id]['status'] = 'scanned';
        return true;
    }

    public function storeSignature(string $id, string $png, int $now): bool
    {
        $row = $this->find($id);
        if ($row === null || !in_array($row['status'], ['open', 'scanned'], true) || $row['expires_at'] <= $now) {
            return false;
        }
        $this->rows[TenantContext::getTenantId()][$id]['status'] = 'signed';
        $this->rows[TenantContext::getTenantId()][$id]['png'] = $png;
        return true;
    }

    public function delete(string $id): bool
    {
        $tenantId = TenantContext::getTenantId();
        if (!isset($this->rows[$tenantId][$id])) {
            return false;
        }
        unset($this->rows[$tenantId][$id]);
        return true;
    }

    public function deleteExpired(int $now): int
    {
        $tenantId = TenantContext::getTenantId();
        $before = count($this->rows[$tenantId] ?? []);
        $this->rows[$tenantId] = array_filter($this->rows[$tenantId] ?? [], fn($r) => $r['expires_at'] > $now);
        return $before - count($this->rows[$tenantId]);
    }
}

class SignatureHandoffServiceTest extends TestCase
{
    private const SIGN_URL = 'https://schule.example/anmeldung/sign.php';

    private InMemorySignatureHandoffRepository $repo;
    private SignatureHandoffService $service;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::reset();
        TenantContext::initialize(1);
        $this->repo = new InMemorySignatureHandoffRepository();
        $this->service = new SignatureHandoffService($this->repo, fn(): int => $this->now);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        parent::tearDown();
    }

    /** @return array{id: string, read_key: string, write_token: string} */
    private function createSession(string $formKey = 'bs'): array
    {
        $result = $this->service->create($formKey, 'unterschrift', 'Unterschrift', 300, 200, self::SIGN_URL);
        $this->assertTrue($result['ok']);
        preg_match('/#s=([a-f0-9]+)&t=([a-f0-9]+)$/', $result['qr_url'], $m);

        return ['id' => $result['id'], 'read_key' => $result['read_key'], 'write_token' => $m[2]];
    }

    private static function png(int $w = 30, int $h = 20): string
    {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($img);
        return 'data:image/png;base64,' . base64_encode((string)ob_get_clean());
    }

    public function testCreateReturnsQrUrlWithIdAndWriteTokenInFragment(): void
    {
        $result = $this->service->create('bs', 'unterschrift', 'Unterschrift', 300, 200, self::SIGN_URL);

        $this->assertTrue($result['ok']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $result['id']);
        $this->assertMatchesRegularExpression(
            '#^' . preg_quote(self::SIGN_URL, '#') . '\#s=' . $result['id'] . '&t=[a-f0-9]{64}$#',
            $result['qr_url']
        );
        $this->assertSame(SignatureHandoffService::TTL_SECONDS, $result['expires_in']);
    }

    public function testReadKeyDiffersFromWriteToken(): void
    {
        $s = $this->createSession();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $s['read_key']);
        $this->assertNotSame($s['read_key'], $s['write_token']);
    }

    public function testSecretsAreStoredOnlyAsHash(): void
    {
        $s = $this->createSession();
        $row = $this->repo->find($s['id']);

        $this->assertSame(hash('sha256', $s['write_token']), $row['write_hash']);
        $this->assertSame(hash('sha256', $s['read_key']), $row['read_hash']);
        $this->assertStringNotContainsString($s['write_token'], serialize($row));
        $this->assertStringNotContainsString($s['read_key'], serialize($row));
    }

    /** @return array<string, array{string, string, int, int, string}> */
    public static function invalidCreateInput(): array
    {
        return [
            'bad form key' => ['../bs', 'f', 300, 200, self::SIGN_URL],
            'empty field' => ['bs', ' ', 300, 200, self::SIGN_URL],
            'too narrow' => ['bs', 'f', 10, 200, self::SIGN_URL],
            'too high' => ['bs', 'f', 300, 5000, self::SIGN_URL],
            'javascript url' => ['bs', 'f', 300, 200, 'javascript:alert(1)'],
            'relative url' => ['bs', 'f', 300, 200, '/sign.php'],
            'url with fragment' => ['bs', 'f', 300, 200, self::SIGN_URL . '#x'],
            'url with credentials' => ['bs', 'f', 300, 200, 'https://u:p@schule.example/sign.php'],
        ];
    }

    /** @dataProvider invalidCreateInput */
    public function testCreateRejectsInvalidInput(string $formKey, string $field, int $w, int $h, string $url): void
    {
        $result = $this->service->create($formKey, $field, '', $w, $h, $url);

        $this->assertSame(['ok' => false, 'error' => SignatureHandoffService::ERROR_INVALID_INPUT], $result);
        $this->assertSame([], $this->repo->rows[1] ?? []);
    }

    public function testCreateAcceptsPluginSignUrlWithQuery(): void
    {
        $result = $this->service->create('bs', 'f', '', 300, 200, 'https://schule.example/wp-admin/admin-ajax.php?action=ondisos_sign');

        $this->assertTrue($result['ok']);
    }

    public function testCreateRefusesBeyondOpenSessionLimitPerTenant(): void
    {
        for ($i = 0; $i < SignatureHandoffService::MAX_OPEN_PER_TENANT; $i++) {
            $this->createSession();
        }

        $result = $this->service->create('bs', 'f', '', 300, 200, self::SIGN_URL);

        $this->assertSame(['ok' => false, 'error' => SignatureHandoffService::ERROR_LIMIT], $result);
    }

    public function testCreateRemovesExpiredSessionsFirst(): void
    {
        for ($i = 0; $i < SignatureHandoffService::MAX_OPEN_PER_TENANT; $i++) {
            $this->createSession();
        }
        $this->now += SignatureHandoffService::TTL_SECONDS;

        $result = $this->service->create('bs', 'f', '', 300, 200, self::SIGN_URL);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $this->repo->rows[1]);
    }

    public function testInfoReturnsFieldDataAndRemainingSecondsAndMarksScanned(): void
    {
        $s = $this->createSession();
        $this->now += 100;

        $info = $this->service->info($s['id'], $s['write_token']);

        $this->assertTrue($info['ok']);
        $this->assertSame('bs', $info['form_key']);
        $this->assertSame('Unterschrift', $info['field_title']);
        $this->assertSame(300, $info['width']);
        $this->assertSame(200, $info['height']);
        $this->assertSame(SignatureHandoffService::TTL_SECONDS - 100, $info['expires_in']);
        $this->assertArrayNotHasKey('expires_at', $info);
        $this->assertSame('scanned', $this->repo->find($s['id'])['status']);
    }

    public function testInfoWithReadKeyIsInvalid(): void
    {
        $s = $this->createSession();

        $this->assertSame(SignatureHandoffService::ERROR_INVALID, $this->service->info($s['id'], $s['read_key'])['error']);
    }

    public function testInfoAfterExpiryReportsExpired(): void
    {
        $s = $this->createSession();
        $this->now += SignatureHandoffService::TTL_SECONDS;

        $this->assertSame(SignatureHandoffService::ERROR_EXPIRED, $this->service->info($s['id'], $s['write_token'])['error']);
    }

    public function testSubmitStoresNormalizedPng(): void
    {
        $s = $this->createSession();

        $this->assertSame(['ok' => true], $this->service->submit($s['id'], $s['write_token'], self::png()));
        $row = $this->repo->find($s['id']);
        $this->assertSame('signed', $row['status']);
        $this->assertStringStartsWith('data:image/png;base64,', $row['png']);
    }

    public function testSubmitWorksOnlyOnce(): void
    {
        $s = $this->createSession();
        $this->service->submit($s['id'], $s['write_token'], self::png());

        $second = $this->service->submit($s['id'], $s['write_token'], self::png());

        $this->assertSame(SignatureHandoffService::ERROR_USED, $second['error']);
    }

    public function testSubmitWithReadKeyIsInvalid(): void
    {
        $s = $this->createSession();

        $this->assertSame(SignatureHandoffService::ERROR_INVALID, $this->service->submit($s['id'], $s['read_key'], self::png())['error']);
    }

    public function testSubmitAfterExpiryFails(): void
    {
        $s = $this->createSession();
        $this->now += SignatureHandoffService::TTL_SECONDS + 1;

        $this->assertSame(SignatureHandoffService::ERROR_EXPIRED, $this->service->submit($s['id'], $s['write_token'], self::png())['error']);
    }

    public function testSubmitRejectsNonPng(): void
    {
        $s = $this->createSession();

        $result = $this->service->submit($s['id'], $s['write_token'], 'data:image/svg+xml;base64,' . base64_encode('<svg/>'));

        $this->assertSame(SignatureHandoffService::ERROR_BAD_IMAGE, $result['error']);
        $this->assertSame('open', $this->repo->find($s['id'])['status']);
    }

    public function testPollBeforeSigningReportsStatusAndRemainingTime(): void
    {
        $s = $this->createSession();
        $this->now += 30;

        $this->assertSame(
            ['ok' => true, 'status' => 'open', 'expires_in' => SignatureHandoffService::TTL_SECONDS - 30],
            $this->service->poll($s['id'], $s['read_key'])
        );
        $this->service->info($s['id'], $s['write_token']);
        $this->assertSame('scanned', $this->service->poll($s['id'], $s['read_key'])['status']);
    }

    public function testPollHandsOutSignatureOnceAndDeletesSession(): void
    {
        $s = $this->createSession();
        $this->service->submit($s['id'], $s['write_token'], self::png());

        $first = $this->service->poll($s['id'], $s['read_key']);
        $second = $this->service->poll($s['id'], $s['read_key']);

        $this->assertSame('signed', $first['status']);
        $this->assertStringStartsWith('data:image/png;base64,', $first['png']);
        $this->assertSame(SignatureHandoffService::ERROR_INVALID, $second['error']);
        $this->assertNull($this->repo->find($s['id']));
    }

    public function testPollWithWriteTokenIsInvalid(): void
    {
        $s = $this->createSession();
        $this->service->submit($s['id'], $s['write_token'], self::png());

        $result = $this->service->poll($s['id'], $s['write_token']);

        $this->assertSame(SignatureHandoffService::ERROR_INVALID, $result['error']);
        $this->assertNotNull($this->repo->find($s['id']));
    }

    public function testPollAfterExpiryReportsExpiredAndDeletes(): void
    {
        $s = $this->createSession();
        $this->service->submit($s['id'], $s['write_token'], self::png());
        $this->now += SignatureHandoffService::TTL_SECONDS;

        $this->assertSame(['ok' => true, 'status' => 'expired', 'expires_in' => 0], $this->service->poll($s['id'], $s['read_key']));
        $this->assertNull($this->repo->find($s['id']));
    }

    public function testSessionOfAnotherTenantIsInvalid(): void
    {
        $s = $this->createSession();
        TenantContext::reset();
        TenantContext::initialize(2);

        $this->assertSame(SignatureHandoffService::ERROR_INVALID, $this->service->info($s['id'], $s['write_token'])['error']);
        $this->assertSame(SignatureHandoffService::ERROR_INVALID, $this->service->poll($s['id'], $s['read_key'])['error']);
    }

    public function testMalformedIdOrTokenIsInvalidWithoutLookup(): void
    {
        $this->assertSame(SignatureHandoffService::ERROR_INVALID, $this->service->info("' OR 1=1 --", str_repeat('a', 64))['error']);
        $this->assertSame(SignatureHandoffService::ERROR_INVALID, $this->service->info(str_repeat('a', 32), 'short')['error']);
    }

    public function testCleanupRemovesOnlyExpiredSessions(): void
    {
        $this->createSession();
        $this->now += SignatureHandoffService::TTL_SECONDS - 100;
        $this->createSession();
        $this->now += 100;

        $this->assertSame(1, $this->service->cleanup());
        $this->assertCount(1, $this->repo->rows[1]);
    }

    public function testNormalizePngAcceptsRealPng(): void
    {
        $this->assertNotNull(SignatureHandoffService::normalizePng(self::png()));
    }

    public function testNormalizePngRemovesTrailingData(): void
    {
        $bytes = base64_decode(substr(self::png(), strlen('data:image/png;base64,')));
        $padded = 'data:image/png;base64,' . base64_encode($bytes . str_repeat('HIDDEN', 50));

        $result = SignatureHandoffService::normalizePng($padded);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('HIDDEN', (string)base64_decode(substr($result, 22)));
    }

    /** @return array<string, array{string}> */
    public static function invalidImages(): array
    {
        return [
            'jpeg' => ['data:image/jpeg;base64,' . base64_encode("\xFF\xD8\xFF\xE0rest")],
            'svg' => ['data:image/svg+xml;base64,' . base64_encode('<svg/>')],
            'png prefix, other bytes' => ['data:image/png;base64,' . base64_encode('GIF89a not a png')],
            'png magic, broken body' => ['data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\nbroken")],
            'not base64' => ['data:image/png;base64,***'],
            'too large' => ['data:image/png;base64,' . str_repeat('A', SignatureHandoffService::MAX_PNG_LENGTH)],
        ];
    }

    /** @dataProvider invalidImages */
    public function testNormalizePngRejectsInvalidImages(string $dataUri): void
    {
        $this->assertNull(SignatureHandoffService::normalizePng($dataUri));
    }
}
