<?php
declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Config\Database;
use App\Repositories\TwoFactorRepository;
use App\Services\TwoFactorLogin;
use App\Services\TwoFactorPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
class TwoFactorLoginTest extends TestCase
{
    private \mysqli $db;
    private TwoFactorRepository $repo;
    private string $key;

    protected function setUp(): void
    {
        $this->db = Database::getConnection();
        $this->repo = new TwoFactorRepository($this->db);
        $this->key = 'platform:test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $esc = $this->db->real_escape_string($this->key);
        $this->db->query("DELETE FROM admin_two_factor WHERE admin_key = '$esc'");
        $this->db->query("DELETE FROM admin_recovery_codes WHERE admin_key = '$esc'");
    }

    private function login(string $mode = 'platform', bool $multiTenant = true, bool $keyConfigured = true): TwoFactorLogin
    {
        return new TwoFactorLogin(new TwoFactorPolicy($mode, $multiTenant, 10), $this->repo, $keyConfigured);
    }

    public function testGraceLoginsCountDownOnlyOnSuccessfulPasswordLogins(): void
    {
        $login = $this->login();
        for ($i = 1; $i <= 10; $i++) {
            $result = $login->evaluate($this->key, true);
            $this->assertSame('grace', $result['outcome'], "login $i");
            $this->assertSame(10 - $i, $result['graceLeft']);
        }
        $this->assertSame(10, $this->repo->graceLoginsUsed($this->key));
    }

    public function testEleventhLoginRequiresSetupAndDoesNotCountFurther(): void
    {
        $login = $this->login();
        for ($i = 0; $i < 10; $i++) {
            $login->evaluate($this->key, true);
        }

        $this->assertSame('setup_required', $login->evaluate($this->key, true)['outcome']);
        $this->assertSame('setup_required', $login->evaluate($this->key, true)['outcome']);
        $this->assertSame(10, $this->repo->graceLoginsUsed($this->key));
    }

    public function testEnrolledAdminGetsCodePrompt(): void
    {
        $this->repo->enable($this->key, 'c', 1, []);
        $result = $this->login()->evaluate($this->key, true);

        $this->assertSame('code', $result['outcome']);
        $this->assertSame(0, $this->repo->graceLoginsUsed($this->key), 'no grace login is used up');
    }

    public function testNothingIsRecordedWhenNotRequired(): void
    {
        $result = $this->login('platform', false)->evaluate($this->key, true);

        $this->assertSame('none', $result['outcome']);
        $this->assertNull($this->repo->find($this->key));
    }

    public function testMissingKeyBlocksLoginOnceGraceIsOver(): void
    {
        $login = $this->login('platform', true, false);
        $result = $login->evaluate($this->key, true);
        $this->assertSame('none', $result['outcome'], 'grace phase: setup is impossible, so do not burn grace logins');
        $this->assertStringContainsString('TWO_FACTOR_KEY', (string)$result['message']);
        $this->assertSame(0, $this->repo->graceLoginsUsed($this->key));

        for ($i = 0; $i < 10; $i++) {
            $this->repo->incrementGraceLogins($this->key);
        }
        $blocked = $login->evaluate($this->key, true);
        $this->assertSame(TwoFactorLogin::OUTCOME_ERROR, $blocked['outcome']);
        $this->assertStringContainsString('TWO_FACTOR_KEY', (string)$blocked['message']);
    }

    public function testMissingKeyBlocksEnrolledAdmin(): void
    {
        $this->repo->enable($this->key, 'c', 1, []);
        $this->assertSame(TwoFactorLogin::OUTCOME_ERROR, $this->login('platform', true, false)->evaluate($this->key, true)['outcome']);
    }

    public function testOffModeLetsEveryoneIn(): void
    {
        $this->repo->enable($this->key, 'c', 1, []);
        $this->assertSame('none', $this->login('off')->evaluate($this->key, true)['outcome']);
    }
}
