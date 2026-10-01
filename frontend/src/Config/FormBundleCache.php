<?php
// frontend/src/Config/FormBundleCache.php

declare(strict_types=1);

namespace Frontend\Config;

/**
 * File cache for the bundle (config + survey + theme) the backend delivers for a form.
 *
 * Two jobs: the stored ETag lets the backend answer "304 not modified", and the stored copy keeps the
 * form available while the backend is unreachable.
 *
 * Entries are keyed by tenant slug and form key. Each file starts with a PHP guard line, so the content
 * is never served as plain text even if the cache directory lies inside a web root (it carries
 * notification e-mail addresses). Writes are atomic (temp file + rename).
 *
 * If the directory cannot be created or written, the cache silently does nothing: the form still works,
 * it just asks the backend every time and has no fallback.
 */
class FormBundleCache
{
    private const GUARD = "<?php http_response_code(404); exit; ?>\n";

    public function __construct(private readonly string $dir)
    {
    }

    /**
     * Default location: FORM_CACHE_DIR, else frontend/cache/forms (outside public/).
     */
    public static function default(): self
    {
        $dir = getenv('FORM_CACHE_DIR');
        return new self(is_string($dir) && $dir !== '' ? $dir : __DIR__ . '/../../cache/forms');
    }

    /**
     * @return array{etag:?string, config:array<string,mixed>, survey_json:?string, theme_json:?string, stored_at:int}|null
     */
    public function get(string $tenantSlug, string $formKey): ?array
    {
        $file = $this->file($tenantSlug, $formKey);
        $raw  = is_file($file) ? @file_get_contents($file) : false;
        if (!is_string($raw) || !str_starts_with($raw, self::GUARD)) {
            return null;
        }

        $data = json_decode(substr($raw, strlen(self::GUARD)), true);
        if (!is_array($data) || !is_array($data['config'] ?? null)) {
            return null;
        }

        return [
            'etag'        => is_string($data['etag'] ?? null) ? $data['etag'] : null,
            'config'      => $data['config'],
            'survey_json' => is_string($data['survey_json'] ?? null) ? $data['survey_json'] : null,
            'theme_json'  => is_string($data['theme_json'] ?? null) ? $data['theme_json'] : null,
            'stored_at'   => is_int($data['stored_at'] ?? null) ? $data['stored_at'] : 0,
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @return bool false if it could not be stored (cache disabled)
     */
    public function put(string $tenantSlug, string $formKey, ?string $etag, array $config, ?string $surveyJson, ?string $themeJson): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            return false;
        }
        if (!is_writable($this->dir)) {
            return false;
        }

        $payload = json_encode([
            'etag'        => $etag,
            'config'      => $config,
            'survey_json' => $surveyJson,
            'theme_json'  => $themeJson,
            'stored_at'   => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return false;
        }

        $file = $this->file($tenantSlug, $formKey);
        $tmp  = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, self::GUARD . $payload, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    public function forget(string $tenantSlug, string $formKey): void
    {
        @unlink($this->file($tenantSlug, $formKey));
    }

    private function file(string $tenantSlug, string $formKey): string
    {
        // Hash: no user-controlled characters in the file name, nothing guessable from outside.
        return rtrim($this->dir, '/') . '/' . hash('sha256', $tenantSlug . "\0" . $formKey) . '.php';
    }
}
