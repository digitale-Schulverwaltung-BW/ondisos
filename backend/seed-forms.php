<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Nur per CLI ausführbar.\n");
}

require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Database;
use App\Config\EnvLoader;

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "Error: .env file not found at {$envFile}\n");
    exit(1);
}

EnvLoader::load($envFile);

try {
    $db = Database::getConnection();
} catch (\RuntimeException $e) {
    fwrite(STDERR, "Error: Cannot connect to database — {$e->getMessage()}\n");
    exit(1);
}

// Config file paths to check — frontend is canonical; backend-local is a fallback
$paths = [
    __DIR__ . '/../frontend/config/forms-config.php',
    __DIR__ . '/config/forms-config.php',
];

$seededTotal  = 0;
$skippedTotal = 0;
$seededPaths  = [];

try {
    $stmt = $db->prepare(
        'INSERT IGNORE INTO form_configs (tenant_id, form_key, config_json) VALUES (1, ?, ?)'
    );

    foreach ($paths as $path) {
        if (!file_exists($path)) {
            echo "No config file at: {$path} — skipping.\n";
            continue;
        }

        $config = require $path;

        if (!is_array($config) || empty($config)) {
            echo "WARNING: {$path} returned no entries — skipping.\n";
            continue;
        }

        $seededPaths[] = $path;
        echo "Reading: {$path}\n";

        foreach ($config as $key => $entry) {
            $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $stmt->bind_param('ss', $key, $json);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                echo "  Seeded:                  {$key}\n";
                $seededTotal++;
            } else {
                echo "  Skipped (already exists): {$key}\n";
                $skippedTotal++;
            }
        }
    }

    $stmt->close();
} catch (\Throwable $e) {
    fwrite(STDERR, "Error during seeding: {$e->getMessage()}\n");
    exit(1);
}

if (empty($seededPaths)) {
    echo "\nNo config files found — skipping. The form_configs table may already be populated.\n";
} else {
    echo "\nDone. {$seededTotal} form(s) seeded, {$skippedTotal} skipped (already existed) for tenant_id=1.\n";
}

echo <<<EOT

NEXT STEPS:
1. Verify form_configs table:
   SELECT * FROM form_configs WHERE tenant_id=1;

2. Delete forms-config.php files (only after verifying the data above):
   - rm frontend/config/forms-config.php
   - rm backend/config/forms-config.php  (if exists)

3. Do NOT run seed-forms.php again after deletion.

EOT;
