<?php
declare(strict_types=1);

/**
 * Notausstieg bei Aussperrung (3.1.2): hebt die Admin-Netz-Einschränkung eines Tenants auf.
 * Aufruf: php clear-network-restriction.php <slug>
 * Für Platform-Admins genügt es, PLATFORM_ADMIN_ALLOWED_NETWORKS in der .env zu leeren.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Nur per CLI ausführbar.\n");
}

require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Database;
use App\Config\EnvLoader;
use App\Repositories\TenantRepository;
use App\Services\AuditLogger;

$slug = $argv[1] ?? '';
if ($slug === '' || str_starts_with($slug, '-')) {
    fwrite(STDERR, "Aufruf: php clear-network-restriction.php <slug>\n");
    exit(2);
}

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "Error: .env file not found at {$envFile}\n");
    exit(2);
}
EnvLoader::load($envFile);

try {
    $repo   = new TenantRepository(Database::getConnection());
    $tenant = $repo->findBySlug($slug);
    if ($tenant === null) {
        fwrite(STDERR, "Tenant '{$slug}' nicht gefunden.\n");
        exit(1);
    }
    $id  = (int)$tenant['id'];
    $old = $repo->getAdminAllowedNetworks($id);
    if ($old === '') {
        echo "Tenant '{$slug}' hat keine Netz-Einschränkung. Nichts zu tun.\n";
        exit(0);
    }
    $repo->update($id, ['admin_allowed_networks' => null]);
    AuditLogger::networkRestrictionChanged($id, $old, '');
    echo "Netz-Einschränkung von Tenant '{$slug}' aufgehoben (vorher: " . str_replace("\n", ', ', $old) . ").\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}
