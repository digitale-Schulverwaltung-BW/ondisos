<?php
declare(strict_types=1);

// Prints SurveyBuilderRules as JSON (the fixture of the JavaScript tests):
//   php backend/tools/dump-survey-rules.php > backend/tests/js/fixtures/survey-rules.json

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../vendor/autoload.php';

echo json_encode(\App\Forms\SurveyBuilderRules::toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
