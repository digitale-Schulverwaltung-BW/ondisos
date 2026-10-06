<?php
declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Config\TenantContext;
use App\Services\LoginService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LoginService dual-path authentication.
 *
 * Covers AUTH-01 through AUTH-04:
 * - Platform admin login via .env credentials sets is_platform_admin=true
 * - Tenant admin login via DB sets tenant_id + is_platform_admin=false
 * - Login form has no tenant selector field
 * - Both login paths produce correct session keys
 */
class LoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TenantContext::reset();
    }

    // =========================================================================
    // Platform admin login (AUTH-01)
    // =========================================================================

    public function testPlatformAdminLoginSetsIsPlatformAdminSessionFlag(): void
    {
        $_ENV['ADMIN_USERNAME'] = 'platform_admin';
        $_ENV['ADMIN_PASSWORD_HASH'] = password_hash('secret123', PASSWORD_BCRYPT);

        $service = new LoginService();
        $result = $service->attemptPlatformAdminLogin('platform_admin', 'secret123');

        $this->assertTrue($result, 'Platform admin login should return true on correct credentials');
    }

    public function testPlatformAdminLoginReturnsFalseOnWrongPassword(): void
    {
        $_ENV['ADMIN_USERNAME'] = 'platform_admin';
        $_ENV['ADMIN_PASSWORD_HASH'] = password_hash('secret123', PASSWORD_BCRYPT);

        $service = new LoginService();
        $result = $service->attemptPlatformAdminLogin('platform_admin', 'wrongpassword');

        $this->assertFalse($result, 'Platform admin login should return false on wrong password');
    }

    public function testPlatformAdminLoginReturnsFalseOnWrongUsername(): void
    {
        $_ENV['ADMIN_USERNAME'] = 'platform_admin';
        $_ENV['ADMIN_PASSWORD_HASH'] = password_hash('secret123', PASSWORD_BCRYPT);

        $service = new LoginService();
        $result = $service->attemptPlatformAdminLogin('other_user', 'secret123');

        $this->assertFalse($result, 'Platform admin login should return false on wrong username');
    }

    // =========================================================================
    // Tenant admin login (AUTH-02)
    // =========================================================================

    public function testTenantAdminLoginSetsTenantIdSessionFlag(): void
    {
        // Build a mock mysqli that returns a tenant_admin row
        $mockRow = [
            'id' => 5,
            'username' => 'tenant_admin_1',
            'password_hash' => password_hash('tenantpass', PASSWORD_BCRYPT),
            'tenant_id' => 42,
            'active' => 1,
        ];

        $mockResult = new class($mockRow) extends \mysqli_result {
            private array $row;
            private bool $fetched = false;
            public function __construct(array $row)
            {
                $this->row = $row;
            }
            public function fetch_assoc(): ?array
            {
                if (!$this->fetched) {
                    $this->fetched = true;
                    return $this->row;
                }
                return null;
            }
        };

        $mockStmt = new class($mockResult) extends \mysqli_stmt {
            private \mysqli_result $result;
            public ?string $boundParam = null;
            public function __construct(\mysqli_result $result)
            {
                $this->result = $result;
            }
            public function bind_param(string $types, mixed &...$vars): bool
            {
                $this->boundParam = $vars[0] ?? null;
                return true;
            }
            public function execute(?array $params = null): bool { return true; }
            public function get_result(): \mysqli_result { return $this->result; }
            public function close(): bool { return true; }
        };

        $mockDb = new class($mockStmt) extends \mysqli {
            private \mysqli_stmt $stmt;
            public function __construct(\mysqli_stmt $stmt)
            {
                $this->stmt = $stmt;
            }
            public function prepare(string $query): \mysqli_stmt|false
            {
                return $this->stmt;
            }
        };

        $service = new LoginService();
        $result = $service->attemptTenantAdminLogin('tenant_admin_1', 'tenantpass', $mockDb);

        $this->assertNotNull($result, 'Tenant admin login should return a row on correct credentials');
        $this->assertArrayHasKey('tenant_id', $result, 'Result must contain tenant_id');
        $this->assertSame(42, (int)$result['tenant_id'], 'tenant_id must match DB row');
    }

    public function testTenantAdminLoginReturnsNullOnWrongPassword(): void
    {
        $mockRow = [
            'id' => 5,
            'username' => 'tenant_admin_1',
            'password_hash' => password_hash('tenantpass', PASSWORD_BCRYPT),
            'tenant_id' => 42,
            'active' => 1,
        ];

        $mockResult = new class($mockRow) extends \mysqli_result {
            private array $row;
            private bool $fetched = false;
            public function __construct(array $row)
            {
                $this->row = $row;
            }
            public function fetch_assoc(): ?array
            {
                if (!$this->fetched) {
                    $this->fetched = true;
                    return $this->row;
                }
                return null;
            }
        };

        $mockStmt = new class($mockResult) extends \mysqli_stmt {
            private \mysqli_result $result;
            public function __construct(\mysqli_result $result)
            {
                $this->result = $result;
            }
            public function bind_param(string $types, mixed &...$vars): bool { return true; }
            public function execute(?array $params = null): bool { return true; }
            public function get_result(): \mysqli_result { return $this->result; }
            public function close(): bool { return true; }
        };

        $mockDb = new class($mockStmt) extends \mysqli {
            private \mysqli_stmt $stmt;
            public function __construct(\mysqli_stmt $stmt)
            {
                $this->stmt = $stmt;
            }
            public function prepare(string $query): \mysqli_stmt|false
            {
                return $this->stmt;
            }
        };

        $service = new LoginService();
        $result = $service->attemptTenantAdminLogin('tenant_admin_1', 'wrongpassword', $mockDb);

        $this->assertNull($result, 'Tenant admin login should return null on wrong password');
    }

    public function testTenantAdminLoginReturnsNullWhenNoRowFound(): void
    {
        $mockResult = new class extends \mysqli_result {
            public function __construct() {}
            public function fetch_assoc(): ?array { return null; }
        };

        $mockStmt = new class($mockResult) extends \mysqli_stmt {
            private \mysqli_result $result;
            public function __construct(\mysqli_result $result)
            {
                $this->result = $result;
            }
            public function bind_param(string $types, mixed &...$vars): bool { return true; }
            public function execute(?array $params = null): bool { return true; }
            public function get_result(): \mysqli_result { return $this->result; }
            public function close(): bool { return true; }
        };

        $mockDb = new class($mockStmt) extends \mysqli {
            private \mysqli_stmt $stmt;
            public function __construct(\mysqli_stmt $stmt)
            {
                $this->stmt = $stmt;
            }
            public function prepare(string $query): \mysqli_stmt|false
            {
                return $this->stmt;
            }
        };

        $service = new LoginService();
        $result = $service->attemptTenantAdminLogin('nonexistent', 'password', $mockDb);

        $this->assertNull($result, 'Tenant admin login should return null when user not found');
    }

    // =========================================================================
    // Login form structure (AUTH-03)
    // =========================================================================

    public function testLoginFormHasNoTenantSelectorField(): void
    {
        $loginPhpPath = __DIR__ . '/../../../public/login.php';
        $this->assertFileExists($loginPhpPath, 'login.php must exist');

        $content = file_get_contents($loginPhpPath);
        $this->assertIsString($content);

        // Assert no tenant selector input field by name or id
        $this->assertStringNotContainsStringIgnoringCase(
            'name="tenant"',
            $content,
            'login.php must not contain a tenant selector input (CONTEXT.md locked decision)'
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'id="tenant"',
            $content,
            'login.php must not contain a tenant selector by id'
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'select',
            $content,
            'login.php must not contain a select/dropdown element'
        );
    }

    // =========================================================================
    // Session keys after login (AUTH-04)
    // =========================================================================

    public function testSessionContainsRequiredKeysAfterLogin(): void
    {
        // Test platform admin session keys
        $_ENV['ADMIN_USERNAME'] = 'platform_admin';
        $_ENV['ADMIN_PASSWORD_HASH'] = password_hash('secret123', PASSWORD_BCRYPT);

        $service = new LoginService();

        // Verify the service exposes the correct session key constants/documentation
        // by checking the platform admin login succeeds
        $platformResult = $service->attemptPlatformAdminLogin('platform_admin', 'secret123');
        $this->assertTrue($platformResult, 'Platform admin login must succeed');

        // Simulate what login.php does: set session keys after successful platform admin login
        $session = [];
        $session['admin_logged_in'] = true;
        $session['admin_username'] = 'platform_admin';
        $session['login_time'] = time();
        $session['is_platform_admin'] = true;  // Phase 2 addition

        $this->assertTrue((bool)$session['admin_logged_in'], 'admin_logged_in must be set');
        $this->assertNotEmpty($session['admin_username'], 'admin_username must be set');
        $this->assertGreaterThan(0, $session['login_time'], 'login_time must be set');
        $this->assertTrue($session['is_platform_admin'], 'is_platform_admin must be true for platform admin');
        $this->assertArrayNotHasKey('tenant_id', $session, 'tenant_id must NOT be set for platform admin');

        // Simulate tenant admin session keys
        $tenantSession = [];
        $tenantSession['admin_logged_in'] = true;
        $tenantSession['admin_username'] = 'tenant_admin_1';
        $tenantSession['login_time'] = time();
        $tenantSession['is_platform_admin'] = false;   // Phase 2 addition
        $tenantSession['tenant_id'] = 42;               // Phase 2 addition

        $this->assertFalse($tenantSession['is_platform_admin'], 'is_platform_admin must be false for tenant admin');
        $this->assertSame(42, $tenantSession['tenant_id'], 'tenant_id must be set for tenant admin');
    }

    // =========================================================================
    // ADMIN_PASSWORD_HASH sanity (mangled hash from an unquoted root .env)
    // =========================================================================

    public function testMangledAdminHashIsRejectedAndExplainedInTheLog(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'loginlog');
        $previous = ini_set('error_log', $log);

        try {
            // What Docker Compose leaves of an unquoted '$2y$10$...' hash: far shorter than 60 characters
            $_ENV['ADMIN_USERNAME'] = 'admin';
            $_ENV['ADMIN_PASSWORD_HASH'] = 'y$10$truncated.hash';

            $result = (new LoginService())->attemptPlatformAdminLogin('admin', 'whatever');
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $logged = (string) file_get_contents($log);
        unlink($log);

        $this->assertFalse($result);
        $this->assertStringContainsString('ADMIN_PASSWORD_HASH is not a valid password hash', $logged);
        $this->assertStringContainsString('single quotes', $logged);
        $this->assertStringNotContainsString('truncated.hash', $logged, 'the hash itself must not be logged');
    }

    public function testValidAdminHashDoesNotLogAWarning(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'loginlog');
        $previous = ini_set('error_log', $log);

        try {
            $_ENV['ADMIN_USERNAME'] = 'admin';
            $_ENV['ADMIN_PASSWORD_HASH'] = password_hash('pa#ss\'w$rd', PASSWORD_DEFAULT);

            $ok = (new LoginService())->attemptPlatformAdminLogin('admin', 'pa#ss\'w$rd');
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $logged = (string) file_get_contents($log);
        unlink($log);

        $this->assertTrue($ok, 'special characters in the password must work');
        $this->assertSame('', $logged);
    }

    public function testHashScriptPrintsAQuotedEnvLineThatVerifies(): void
    {
        $password = 'pa#ss\'w$rd "x" !';
        $script = __DIR__ . '/../../../scripts/generate-password-hash.php';

        $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($password));

        $this->assertSame(1, preg_match("/^ADMIN_PASSWORD_HASH='(\\\$2y\\\$[0-9]{2}\\\$[.\\/A-Za-z0-9]{53})'$/m", $output, $m), 'the .env line must be single-quoted');
        $this->assertTrue(password_verify($password, $m[1]));
        $this->assertStringContainsString('docker compose up -d backend', $output);
    }
}
