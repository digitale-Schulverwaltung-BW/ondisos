<?php
declare(strict_types=1);

// Prints the texts of the graphical survey builder (config/messages.php, group "builder") as JSON, the fixture of the
// JavaScript tests (they check that every text the script asks for exists):
//   php backend/tools/dump-builder-messages.php > backend/tests/js/fixtures/builder-messages.json

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

$messages = require __DIR__ . '/../config/messages.php';

echo json_encode($messages['builder'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
