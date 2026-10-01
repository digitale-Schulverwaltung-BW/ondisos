<?php
declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\Database;
use PHPUnit\Framework\TestCase;

/**
 * Database::connectionHint() — explains the "MySQL volume keeps its original credentials" trap
 * that migrate.php / seed-forms.php print when the connection is refused.
 */
class DatabaseConnectionHintTest extends TestCase
{
    public function testAccessDeniedGetsTheVolumeHint(): void
    {
        $hint = Database::connectionHint("Access denied for user 'anmeldung'@'172.24.0.3' (using password: YES)");

        $this->assertNotNull($hint);
        $this->assertStringContainsString('CREATED with', $hint);
        $this->assertStringContainsString('ONLY the MySQL volume', $hint);
        $this->assertStringContainsString('down -v', $hint, 'must warn against down -v');
        $this->assertStringContainsString('DEPLOYMENT.md', $hint);
    }

    public function testUnknownDatabaseGetsTheSameHint(): void
    {
        $this->assertNotNull(Database::connectionHint("Unknown database 'anmeldung'"));
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $this->assertNotNull(Database::connectionHint('ACCESS DENIED for user'));
    }

    /**
     * @return array<string,array{string}>
     */
    public static function unrelatedMessages(): array
    {
        return [
            'refused'  => ['Connection refused'],
            'host'     => ['php_network_getaddresses: getaddrinfo for mysql failed: Name or service not known'],
            'empty'    => [''],
        ];
    }

    /**
     * @dataProvider unrelatedMessages
     */
    public function testOtherErrorsGetNoHint(string $message): void
    {
        $this->assertNull(Database::connectionHint($message));
    }

    public function testHintNeverEchoesCredentials(): void
    {
        $hint = (string) Database::connectionHint("Access denied for user 'anmeldung'@'x' (using password: YES)");

        $this->assertStringNotContainsString('secret', strtolower($hint));
        $this->assertStringContainsString('<new>', $hint, 'placeholder instead of a real password');
    }
}
