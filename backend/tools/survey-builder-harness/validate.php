<?php
declare(strict_types=1);

// Reads a survey from stdin and prints what the server's checks say, as JSON (used by check.mjs):
//   {"valid": bool, "errors": [...], "warnings": [...]}
// With the argument "theme" it checks a theme instead (ThemeValidator, as theme_preview_check.php does).

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../../vendor/autoload.php';

$text = (string)stream_get_contents(STDIN);
$validator = ($argv[1] ?? '') === 'theme' ? new \App\Forms\ThemeValidator() : new \App\Forms\SurveyValidator();
['result' => $result] = $validator->validate($text);

echo json_encode(['valid' => $result->isValid(), 'errors' => $result->errors(), 'warnings' => $result->warnings()], JSON_UNESCAPED_UNICODE), "\n";
