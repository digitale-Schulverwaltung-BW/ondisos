<?php
declare(strict_types=1);

/**
 * Rate limit for write actions of the form editor. Call editor_rate_limit() for POST requests; it answers 429 and
 * stops when the limit is exceeded. Settings (.env): RATE_LIMIT_ENABLED, EDITOR_RATE_LIMIT_MAX (default 60), EDITOR_RATE_LIMIT_WINDOW (default 60 s).
 *
 * A page that defines EDITOR_RATE_LIMIT_SCOPE = 'preview' before the editor bootstrap (the check of the live preview)
 * counts in its own bucket: EDITOR_PREVIEW_RATE_LIMIT_MAX (default 300) per EDITOR_RATE_LIMIT_WINDOW.
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

    $preview = defined('EDITOR_RATE_LIMIT_SCOPE') && EDITOR_RATE_LIMIT_SCOPE === EditorRateLimit::SCOPE_PREVIEW;
    $max = $preview
        ? (int)\App\Config\EnvLoader::get('EDITOR_PREVIEW_RATE_LIMIT_MAX', (string)EditorRateLimit::DEFAULT_PREVIEW_MAX)
        : (int)\App\Config\EnvLoader::get('EDITOR_RATE_LIMIT_MAX', (string)EditorRateLimit::DEFAULT_MAX);

    $limit = new EditorRateLimit(new RateLimiter(
        __DIR__ . '/../cache/ratelimit',
        $max,
        (int)\App\Config\EnvLoader::get('EDITOR_RATE_LIMIT_WINDOW', (string)EditorRateLimit::DEFAULT_WINDOW),
    ), $preview ? EditorRateLimit::SCOPE_PREVIEW : EditorRateLimit::SCOPE_EDITOR);

    $retry = $limit->hit((string)($_SESSION['admin_username'] ?? 'anonymous'), \App\Utils\ClientIp::get());
    if ($retry !== null) {
        header('Retry-After: ' . $retry);
        http_response_code(429);
        if ($preview) {
            header('Content-Type: application/json; charset=utf-8');
            die(json_encode(['ok' => false, 'error' => 'rate_limited', 'retry_after' => $retry]));
        }
        die('Zu viele Änderungen in kurzer Zeit. Bitte in ' . $retry . ' Sekunden erneut versuchen.');
    }
}
