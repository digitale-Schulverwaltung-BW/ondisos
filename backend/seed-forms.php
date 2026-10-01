<?php
declare(strict_types=1);

/**
 * Seed form configurations into the database (table form_configs, tenant_id = 1).
 *
 * The active configuration of a form lives in the database; a forms-config.php is only an import source.
 * Existing entries are never overwritten (INSERT IGNORE) — new form keys are added.
 *
 * Usage:
 *   php seed-forms.php                 Read ../frontend/config/forms-config.php and config/forms-config.php (if present)
 *   php seed-forms.php <file>          Read only this file
 *   php seed-forms.php -               Read the configuration from STDIN
 *
 * Docker (the backend container cannot see frontend/, so pass the file in via STDIN — no copy needed):
 *   docker compose exec -T backend php seed-forms.php - < frontend/config/forms-config.php
 *
 * The source is PHP code that is executed (it is a `return [...]` file, like forms-config.php): only use files you trust.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Nur per CLI ausführbar.\n");
}

// --- Arguments ---------------------------------------------------------------------------------
$arg = $argv[1] ?? null;

if (in_array($arg, ['-h', '--help'], true)) {
    echo <<<EOT
Usage:
  php seed-forms.php            Read ../frontend/config/forms-config.php and config/forms-config.php (if present)
  php seed-forms.php <file>     Read only this file
  php seed-forms.php -          Read the configuration from STDIN

Docker:
  docker compose exec -T backend php seed-forms.php - < frontend/config/forms-config.php

Existing form entries are never overwritten; new form keys are added (tenant_id = 1).

EOT;
    exit(0);
}

if ($argc > 2) {
    fwrite(STDERR, "Error: too many arguments (see --help)\n");
    exit(1);
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
    $hint = Database::connectionHint($e->getMessage());
    if ($hint !== null) {
        fwrite(STDERR, "\n{$hint}\n");
    }
    exit(1);
}

// --- Sources -----------------------------------------------------------------------------------
// Each source: ['label' => shown to the user, 'path' => file to include, 'temp' => delete afterwards]
$sources = [];
$explicit = $arg !== null;

if ($arg === '-') {
    $code = stream_get_contents(STDIN);
    if ($code === false || trim($code) === '') {
        fwrite(STDERR, "Error: nothing received on STDIN. Example:\n  docker compose exec -T backend php seed-forms.php - < frontend/config/forms-config.php\n");
        exit(1);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'forms-config-');
    file_put_contents($tmp, $code);
    $sources[] = ['label' => 'STDIN', 'path' => $tmp, 'temp' => true];
} elseif ($arg !== null) {
    $sources[] = ['label' => $arg, 'path' => $arg, 'temp' => false];
} else {
    // Default: frontend is canonical; backend-local is a fallback (manual installs, or a file placed here)
    foreach ([__DIR__ . '/../frontend/config/forms-config.php', __DIR__ . '/config/forms-config.php'] as $path) {
        $sources[] = ['label' => $path, 'path' => $path, 'temp' => false];
    }
}

$seededTotal  = 0;
$skippedTotal = 0;
$readSources  = 0;
$exampleForms = [];

try {
    $stmt = $db->prepare(
        'INSERT IGNORE INTO form_configs (tenant_id, form_key, config_json) VALUES (1, ?, ?)'
    );

    foreach ($sources as $source) {
        $path  = $source['path'];
        $label = $source['label'];

        if (!is_file($path)) {
            if ($explicit) {
                fwrite(STDERR, "Error: file not found: {$label}\n");
                exit(1);
            }
            echo "No config file at: {$label} — skipping.\n";
            continue;
        }

        $config = require $path;

        if ($source['temp']) {
            @unlink($path);
        }

        if (!is_array($config) || empty($config)) {
            if ($explicit) {
                fwrite(STDERR, "Error: {$label} did not return a non-empty array (expected `return ['form' => [...]];`)\n");
                exit(1);
            }
            echo "WARNING: {$label} returned no entries — skipping.\n";
            continue;
        }

        $readSources++;
        echo "Reading: {$label}\n";

        foreach ($config as $key => $entry) {
            if (!is_string($key) || !is_array($entry)) {
                echo "  Ignored (not a form entry): " . (is_string($key) ? $key : '#' . $key) . "\n";
                continue;
            }

            // Placeholder addresses from forms-config-dist.php should not end up in a real database unnoticed
            if (preg_match('/@example\.(com|org|net)\b/i', (string)($entry['notify_email'] ?? ''))) {
                $exampleForms[] = $key;
            }

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

if ($readSources === 0) {
    echo "\nNo config files found — skipping. The form_configs table may already be populated.\n";
    echo "Pass a file or STDIN explicitly: php seed-forms.php <file>   |   php seed-forms.php - < forms-config.php\n";
} else {
    echo "\nDone. {$seededTotal} form(s) seeded, {$skippedTotal} skipped (already existed) for tenant_id=1.\n";
}

if ($exampleForms !== []) {
    $list = implode(', ', array_unique($exampleForms));
    echo <<<EOT

WARNING: these forms still use a placeholder notify_email (…@example.com/.org/.net): {$list}
         forms-config-dist.php is a TEMPLATE with example forms and addresses. Use your own forms-config.php, or fix the
         entries in the database (UPDATE form_configs SET config_json = … WHERE tenant_id = 1 AND form_key = '…').

EOT;
}

echo <<<EOT

NEXT STEPS:
1. Verify the form_configs table:
   SELECT form_key, JSON_EXTRACT(config_json, '$.notify_email') AS notify_email FROM form_configs WHERE tenant_id = 1;

2. The forms-config.php file is only an import source now — the frontend never reads it. You may delete it (also any copy
   at backend/config/forms-config.php). Running this script again is harmless: it adds new forms and never overwrites.

3. To change an existing form later, edit form_configs.config_json (SQL); re-seeding does not overwrite it.

EOT;
