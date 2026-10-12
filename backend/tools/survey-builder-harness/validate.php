<?php
declare(strict_types=1);

// Reads a survey from stdin and prints what the server's checks say, as JSON (used by check.mjs):
//   {"valid": bool, "errors": [...], "warnings": [...]}

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../../vendor/autoload.php';

$text = (string)stream_get_contents(STDIN);
['result' => $result] = (new \App\Forms\SurveyValidator())->validate($text);

echo json_encode(['valid' => $result->isValid(), 'errors' => $result->errors(), 'warnings' => $result->warnings()], JSON_UNESCAPED_UNICODE), "\n";
