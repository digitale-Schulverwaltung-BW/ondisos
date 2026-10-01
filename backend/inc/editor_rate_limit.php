<?php
declare(strict_types=1);

/**
 * Rate limit for write actions of the form editor. Call editor_rate_limit() for POST requests; it answers 429 and
 * stops when the limit is exceeded. Settings (.env): RATE_LIMIT_ENABLED, EDITOR_RATE_LIMIT_MAX (default 60), EDITOR_RATE_LIMIT_WINDOW (default 60 s).
 */

use App\Forms\EditorRateLimit;
use App\Services\RateLimiter;

function editor_rate_limit(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (!filter_var(\App\Config\EnvLoader::get('RATE_LIMIT_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN)) {
        return;
    }

    $limit = new EditorRateLimit(new RateLimiter(
        __DIR__ . '/../cache/ratelimit',
        (int)\App\Config\EnvLoader::get('EDITOR_RATE_LIMIT_MAX', (string)EditorRateLimit::DEFAULT_MAX),
        (int)\App\Config\EnvLoader::get('EDITOR_RATE_LIMIT_WINDOW', (string)EditorRateLimit::DEFAULT_WINDOW),
    ));

    $retry = $limit->hit((string)($_SESSION['admin_username'] ?? 'anonymous'), (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($retry !== null) {
        header('Retry-After: ' . $retry);
        http_response_code(429);
        die('Zu viele Änderungen in kurzer Zeit. Bitte in ' . $retry . ' Sekunden erneut versuchen.');
    }
}
