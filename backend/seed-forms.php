<?php
declare(strict_types=1);

/**
 * Seed form configurations into the database (table form_configs) for a tenant (default: tenant "default", id 1).
 *
 * The active configuration of a form lives in the database; a forms-config.php is only an import source.
 * Existing entries are never overwritten — new form keys are added. Every entry is validated first; an invalid
 * entry is reported and skipped.
 *
 * Usage:
 *   php seed-forms.php [--tenant=<slug>]                 Read ../frontend/config/forms-config.php and config/forms-config.php (if present)
 *   php seed-forms.php [--tenant=<slug>] <file>          Read only this file
 *   php seed-forms.php [--tenant=<slug>] -               Read the configuration from STDIN
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

require_once __DIR__ . '/vendor/autoload.php';

use App\Cli\CliArgs;
use App\Config\Database;
use App\Config\EnvLoader;
use App\Config\TenantContext;
use App\Repositories\FormConfigRepository;
use App\Repositories\TenantRepository;
use App\Services\FormSeedService;

// --- Arguments ---------------------------------------------------------------------------------
$cli = new CliArgs(array_slice($argv, 1));

if ($cli->flag('help') || in_array('-h', $argv, true)) {
    echo <<<EOT
Usage:
  php seed-forms.php [--tenant=<slug>]            Read ../frontend/config/forms-config.php and config/forms-config.php (if present)
  php seed-forms.php [--tenant=<slug>] <file>     Read only this file
  php seed-forms.php [--tenant=<slug>] -          Read the configuration from STDIN

  --tenant=<slug>   tenant to seed (default: default = tenant 1)

Docker:
  docker compose exec -T backend php seed-forms.php - < frontend/config/forms-config.php

Existing form entries are never overwritten; new form keys are added. Invalid entries are reported and skipped.

EOT;
    exit(0);
}

$unknown = $cli->unknownOptions(['tenant', 'help']);
if ($unknown !== [] || count($cli->positional) > 1) {
    fwrite(STDERR, ($unknown !== [] ? 'Error: unknown option --' . implode(', --', $unknown) : 'Error: too many arguments') . " (see --help)\n");
    exit(1);
}
$arg = $cli->positional[0] ?? null;

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

// --- Tenant ------------------------------------------------------------------------------------
$slug   = $cli->value('tenant') ?? 'default';
$tenant = (new TenantRepository($db))->findBySlug($slug);
if ($tenant === null) {
    fwrite(STDERR, "Error: unknown tenant '{$slug}'\n");
    exit(1);
}
$tenantId = (int)$tenant['id'];
TenantContext::initialize($tenantId);
$seeder = new FormSeedService(new FormConfigRepository($db));

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
$invalidTotal = 0;
$readSources  = 0;
$exampleForms = [];

try {
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
        echo "Reading: {$label} (tenant: {$slug})\n";

        foreach ($seeder->seed($config) as $key => $result) {
            switch ($result['status']) {
                case FormSeedService::STATUS_SEEDED:
                    echo "  Seeded:                  {$key}\n";
                    $seededTotal++;
                    break;
                case FormSeedService::STATUS_SKIPPED:
                    echo "  Skipped (already exists): {$key}\n";
                    $skippedTotal++;
                    break;
                case FormSeedService::STATUS_INVALID:
                    echo "  INVALID (not seeded):    {$key}\n";
                    foreach ($result['validation']->errors() as $e) {
                        echo '      ' . ($e['path'] !== '' ? $e['path'] . ': ' : '') . $e['message'] . "\n";
                    }
                    $invalidTotal++;
                    break;
                default:
                    echo "  Ignored (not a form entry): {$key}\n";
            }

            // Placeholder addresses from forms-config-dist.php should not end up in a real database unnoticed
            $entry = $config[$key] ?? null;
            if (is_array($entry) && preg_match('/@example\.(com|org|net)\b/i', (string)(is_array($entry['notify_email'] ?? null) ? implode(',', $entry['notify_email']) : ($entry['notify_email'] ?? '')))) {
                $exampleForms[] = $key;
            }
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "Error during seeding: {$e->getMessage()}\n");
    exit(1);
}

// Forms in the database (not only those just read) that would throw submissions away: db=false and no
// valid notify_email. The frontend refuses such forms (see Frontend\Config\FormConfig::discardsSubmissions()).
$discardingForms = [];
$scan = $db->prepare('SELECT form_key, config_json FROM form_configs WHERE tenant_id = ? ORDER BY form_key');
$scan->bind_param('i', $tenantId);
$scan->execute();
$rows = $scan->get_result();
while ($rows && ($row = $rows->fetch_assoc())) {
    $entry = json_decode((string) $row['config_json'], true);
    if (!is_array($entry)) {
        continue;
    }

    $storesInDb = (bool) ($entry['db'] ?? true);
    $recipients = $entry['notify_email'] ?? '';
    $recipients = array_filter(array_map('trim', is_array($recipients) ? $recipients : explode(',', (string) $recipients)));
    $hasValidMail = $recipients !== [] && count(array_filter($recipients, static fn ($r) => filter_var($r, FILTER_VALIDATE_EMAIL))) === count($recipients);

    if (!$storesInDb && !$hasValidMail) {
        $discardingForms[] = $row['form_key'];
    }
}

if ($readSources === 0) {
    echo "\nNo config files found — skipping. The form_configs table may already be populated.\n";
    echo "Pass a file or STDIN explicitly: php seed-forms.php <file>   |   php seed-forms.php - < forms-config.php\n";
} else {
    echo "\nDone. {$seededTotal} form(s) seeded, {$skippedTotal} skipped (already existed)"
        . ($invalidTotal > 0 ? ", {$invalidTotal} invalid" : '') . " for tenant '{$slug}' (id {$tenantId}).\n";
}

if ($exampleForms !== []) {
    $list = implode(', ', array_unique($exampleForms));
    echo <<<EOT

WARNING: these forms still use a placeholder notify_email (…@example.com/.org/.net): {$list}
         forms-config-dist.php is a TEMPLATE with example forms and addresses. Use your own forms-config.php, or fix the
         entries in the database (UPDATE form_configs SET config_json = … WHERE tenant_id = {$tenantId} AND form_key = '…').

EOT;
}

if ($discardingForms !== []) {
    $list = implode(', ', $discardingForms);
    echo <<<EOT

WARNING: these forms store nothing (db: false) and have no valid notify_email, so their submissions would be DISCARDED.
         The frontend therefore refuses to show/accept them until this is fixed: {$list}
         Either store them:   UPDATE form_configs SET config_json = JSON_SET(config_json, '$.db', true) WHERE tenant_id = {$tenantId} AND form_key = '<form>';
         or add a recipient:  UPDATE form_configs SET config_json = JSON_SET(config_json, '$.notify_email', 'sekretariat@your-school.example') WHERE tenant_id = {$tenantId} AND form_key = '<form>';
         (Re-seeding never overwrites existing entries.)

EOT;
}

echo <<<EOT

NEXT STEPS:
1. Verify the form_configs table:
   SELECT form_key, JSON_EXTRACT(config_json, '$.notify_email') AS notify_email FROM form_configs WHERE tenant_id = {$tenantId};

2. Survey and theme files: import them into the database with import-surveys.php (3.1), or leave them in frontend/surveys/
   (the frontend uses the files whenever the database holds no survey for a form).

3. The forms-config.php file is only an import source now — the frontend never reads it. You may delete it (also any copy
   at backend/config/forms-config.php). Running this script again is harmless: it adds new forms and never overwrites.

4. To change an existing form later, edit form_configs.config_json (SQL; the form editor of 3.1 will do this in the backend);
   re-seeding does not overwrite it.

EOT;

exit($invalidTotal > 0 ? 1 : 0);
