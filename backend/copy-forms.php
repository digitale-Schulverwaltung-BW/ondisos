<?php
declare(strict_types=1);

/**
 * Copy forms from one tenant to another (3.1), e.g. to equip a new school.
 * Usage and options: php copy-forms.php --help
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Nur per CLI ausführbar.\n");
}

require_once __DIR__ . '/vendor/autoload.php';

use App\Cli\CopyFormsCommand;
use App\Config\Database;
use App\Config\EnvLoader;
use App\Repositories\FormConfigRepository;
use App\Repositories\FormResourceRepository;
use App\Repositories\FormRevisionRepository;
use App\Repositories\TenantRepository;
use App\Services\FormCopyService;

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "Error: .env file not found at {$envFile}\n");
    exit(2);
}
EnvLoader::load($envFile);

$args = array_slice($argv, 1);
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo CopyFormsCommand::usage();
    exit(0);
}

try {
    $db = Database::getConnection();
} catch (\RuntimeException $e) {
    fwrite(STDERR, "Error: Cannot connect to database — {$e->getMessage()}\n");
    $hint = Database::connectionHint($e->getMessage());
    if ($hint !== null) {
        fwrite(STDERR, "\n{$hint}\n");
    }
    exit(2);
}

$tenants = new TenantRepository($db);
$command = new CopyFormsCommand(
    $tenants,
    new FormCopyService($db, $tenants, new FormConfigRepository($db), new FormResourceRepository($db), new FormRevisionRepository($db)),
);

exit($command->run($args, static fn (string $s) => print($s), static fn (string $s) => fwrite(STDERR, $s)));
