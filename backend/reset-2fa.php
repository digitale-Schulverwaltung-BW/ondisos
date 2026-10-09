<?php
declare(strict_types=1);

/**
 * Notausstieg (3.1.2): setzt die Zwei-Faktor-Anmeldung eines Admins zurück (Faktor und Recovery-Codes
 * werden gelöscht, die Schonfrist-Anmeldungen beginnen von vorn).
 *
 * Aufruf: php reset-2fa.php <benutzer>
 *   <benutzer>  Benutzername des Platform-Admins (ADMIN_USERNAME) oder eines Schul-Admins
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Nur per CLI ausführbar.\n");
}

require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Database;
use App\Config\EnvLoader;
use App\Repositories\TwoFactorRepository;
use App\Services\AdminKey;
use App\Services\AuditLogger;

$username = $argv[1] ?? '';
if ($username === '' || str_starts_with($username, '-')) {
    fwrite(STDERR, "Aufruf: php reset-2fa.php <benutzer>\n");
    exit(2);
}

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "Error: .env file not found at {$envFile}\n");
    exit(2);
}
EnvLoader::load($envFile);

try {
    $db = Database::getConnection();
    $keys = [];

    if ($username === (string)EnvLoader::get('ADMIN_USERNAME', '')) {
        $keys[] = AdminKey::forPlatform($username);
    }
    $stmt = $db->prepare('SELECT id FROM tenant_admins WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $keys[] = AdminKey::forTenantAdmin((int)$row['id']);
    }
    $stmt->close();

    if ($keys === []) {
        fwrite(STDERR, "Benutzer '{$username}' nicht gefunden.\n");
        exit(1);
    }

    $repo = new TwoFactorRepository($db);
    foreach ($keys as $key) {
        $repo->remove($key, true);
        AuditLogger::twoFactorEvent('two_factor_reset', 'cli', ['target' => $username, 'admin_key' => $key]);
        echo "Zwei-Faktor-Anmeldung zurückgesetzt: {$key}\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}
