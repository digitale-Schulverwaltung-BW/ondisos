<?php
declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Config\Database;
use App\Repositories\TwoFactorRepository;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
class TwoFactorRepositoryTest extends TestCase
{
    private \mysqli $db;
    private TwoFactorRepository $repo;
    private string $keyA;
    private string $keyB;

    protected function setUp(): void
    {
        $this->db = Database::getConnection();
        $this->repo = new TwoFactorRepository($this->db);
        $suffix = bin2hex(random_bytes(4));
        $this->keyA = 'tenant:test-a-' . $suffix;
        $this->keyB = 'tenant:test-b-' . $suffix;
    }

    protected function tearDown(): void
    {
        foreach ([$this->keyA, $this->keyB] as $key) {
            $this->db->query("DELETE FROM admin_two_factor WHERE admin_key = '" . $this->db->real_escape_string($key) . "'");
            $this->db->query("DELETE FROM admin_recovery_codes WHERE admin_key = '" . $this->db->real_escape_string($key) . "'");
        }
    }

    public function testUnknownAdminHasNoFactorAndNoGraceLoginsUsed(): void
    {
        $this->assertFalse($this->repo->hasFactor($this->keyA));
        $this->assertSame(0, $this->repo->graceLoginsUsed($this->keyA));
        $this->assertNull($this->repo->find($this->keyA));
    }

    public function testGraceLoginsAreCountedPerAdmin(): void
    {
        $this->repo->incrementGraceLogins($this->keyA);
        $this->repo->incrementGraceLogins($this->keyA);
        $this->repo->incrementGraceLogins($this->keyB);

        $this->assertSame(2, $this->repo->graceLoginsUsed($this->keyA));
        $this->assertSame(1, $this->repo->graceLoginsUsed($this->keyB));
        $this->assertFalse($this->repo->hasFactor($this->keyA), 'counting grace logins enrols nothing');
    }

    public function testEnableStoresFactorAndRecoveryCodesTogether(): void
    {
        $this->repo->enable($this->keyA, 'ciphertext', 100, ['h1', 'h2', 'h3']);

        $this->assertTrue($this->repo->hasFactor($this->keyA));
        $row = $this->repo->find($this->keyA);
        $this->assertSame('ciphertext', $row['totp_secret_enc']);
        $this->assertSame(100, $row['last_totp_step']);
        $this->assertSame(3, $this->repo->remainingRecoveryCodes($this->keyA));
        $this->assertFalse($this->repo->hasFactor($this->keyB), 'other admins are untouched');
    }

    public function testEnableKeepsTheGraceCounter(): void
    {
        $this->repo->incrementGraceLogins($this->keyA);
        $this->repo->enable($this->keyA, 'c', 1, []);
        $this->assertSame(1, $this->repo->graceLoginsUsed($this->keyA));
    }

    public function testTotpStepCanOnlyMoveForward(): void
    {
        $this->repo->enable($this->keyA, 'c', 100, []);

        $this->assertFalse($this->repo->acceptTotpStep($this->keyA, 100), 'same step = replay');
        $this->assertFalse($this->repo->acceptTotpStep($this->keyA, 99));
        $this->assertTrue($this->repo->acceptTotpStep($this->keyA, 101));
        $this->assertFalse($this->repo->acceptTotpStep($this->keyA, 101));
        $this->assertSame(101, $this->repo->find($this->keyA)['last_totp_step']);
    }

    public function testRecoveryCodeWorksExactlyOnce(): void
    {
        $this->repo->enable($this->keyA, 'c', 1, ['hash-1', 'hash-2']);

        $this->assertTrue($this->repo->consumeRecoveryCode($this->keyA, 'hash-1'));
        $this->assertFalse($this->repo->consumeRecoveryCode($this->keyA, 'hash-1'));
        $this->assertFalse($this->repo->consumeRecoveryCode($this->keyA, 'unknown'));
        $this->assertSame(1, $this->repo->remainingRecoveryCodes($this->keyA));
    }

    public function testRecoveryCodeOfAnotherAdminDoesNotWork(): void
    {
        $this->repo->enable($this->keyA, 'c', 1, ['hash-1']);
        $this->assertFalse($this->repo->consumeRecoveryCode($this->keyB, 'hash-1'));
        $this->assertSame(1, $this->repo->remainingRecoveryCodes($this->keyA));
    }

    public function testReplaceRecoveryCodesInvalidatesOldOnes(): void
    {
        $this->repo->enable($this->keyA, 'c', 1, ['old']);
        $this->repo->replaceRecoveryCodes($this->keyA, ['new-1', 'new-2']);

        $this->assertFalse($this->repo->consumeRecoveryCode($this->keyA, 'old'));
        $this->assertTrue($this->repo->consumeRecoveryCode($this->keyA, 'new-1'));
    }

    public function testRemoveByAdminKeepsGraceCounter(): void
    {
        $this->repo->incrementGraceLogins($this->keyA);
        $this->repo->enable($this->keyA, 'c', 1, ['h']);
        $this->repo->remove($this->keyA, false);

        $this->assertFalse($this->repo->hasFactor($this->keyA));
        $this->assertSame(0, $this->repo->remainingRecoveryCodes($this->keyA));
        $this->assertSame(1, $this->repo->graceLoginsUsed($this->keyA));
    }

    public function testResetByOperatorRestartsGraceLogins(): void
    {
        $this->repo->incrementGraceLogins($this->keyA);
        $this->repo->incrementGraceLogins($this->keyA);
        $this->repo->enable($this->keyA, 'c', 1, ['h']);
        $this->repo->remove($this->keyA, true);

        $this->assertFalse($this->repo->hasFactor($this->keyA));
        $this->assertSame(0, $this->repo->graceLoginsUsed($this->keyA));
    }
}
