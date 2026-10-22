<?php
// frontend/src/Services/SignatureRelay.php

declare(strict_types=1);

namespace Frontend\Services;

/**
 * Relay for the signature on a smartphone (3.2.0a): browser ⇄ frontend ⇄ backend.
 *
 * Shared by the standalone frontend (public/api/signature.php) and the WordPress plugin. The caller checks
 * CSRF (token or nonce) and says whether it was valid; this class decides which actions need it, passes only
 * the expected fields on and hands only the expected fields back.
 *
 * - create and poll come from the form page and need a valid CSRF token; info and submit come from the phone,
 *   where the write token from the QR code is the authorization.
 * - The URL of the phone page (sign_url) is always set by the server, never taken from the browser:
 *   otherwise a manipulated request could put a foreign address into the QR code.
 * - Backend errors keep their code (expired, used …) and 4xx status; an unreachable or rejecting backend
 *   (missing secret, 401, 5xx) becomes "unavailable" for the browser; the PHP error log gets the backend's
 *   HTTP status and error code (never tokens or image data).
 */
final class SignatureRelay
{
    public const ACTIONS = ['create', 'info', 'submit', 'poll'];
    public const ACTIONS_NEEDING_CSRF = ['create', 'poll'];
    /** Request body limit: a signature PNG (≤ 200 000 characters) plus a few fields. */
    public const MAX_BODY_BYTES = 262144;

    /** Fields handed back to the browser per action. */
    private const RESPONSE_FIELDS = [
        'create' => ['id', 'read_key', 'qr_svg', 'sign_link', 'expires_in'],
        'info'   => ['school', 'form_key', 'field_title', 'width', 'height', 'pen_color', 'background_color', 'expires_in'],
        'submit' => [],
        'poll'   => ['status', 'expires_in', 'png'],
    ];

    /** @var (\Closure(string): string)|null */
    private ?\Closure $formTitle;

    /**
     * @param (\Closure(string): string)|null $formTitle title of a form by key, shown on the phone page
     */
    public function __construct(
        private BackendApiClient $client,
        ?\Closure $formTitle = null
    ) {
        $this->formTitle = $formTitle;
    }

    /**
     * @param array<string, mixed> $input decoded JSON body from the browser
     * @return array{code: int, body: array<string, mixed>}
     */
    public function handle(array $input, bool $csrfValid, string $signUrl): array
    {
        $action = is_string($input['action'] ?? null) ? $input['action'] : '';
        if (!in_array($action, self::ACTIONS, true)) {
            return self::error(400, 'invalid_input');
        }
        if (in_array($action, self::ACTIONS_NEEDING_CSRF, true) && !$csrfValid) {
            return self::error(403, 'csrf');
        }

        $str = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
        $int = static fn(string $key): int => is_int($input[$key] ?? null) || (is_string($input[$key] ?? null) && ctype_digit($input[$key]))
            ? (int)$input[$key] : 0;

        $params = match ($action) {
            'create' => [
                'form_key' => $str('form_key'),
                'field_name' => $str('field_name'),
                'field_title' => $str('field_title'),
                'width' => $int('width'),
                'height' => $int('height'),
                'pen_color' => $str('pen_color'),
                'background_color' => $str('background_color'),
                'sign_url' => $signUrl,
            ],
            'info' => ['id' => $str('id'), 'token' => $str('token')],
            'submit' => ['id' => $str('id'), 'token' => $str('token'), 'png' => $str('png')],
            'poll' => ['id' => $str('id'), 'key' => $str('key')],
        };

        $response = $this->client->signatureHandoff($action, $params);
        $code = $response['code'];
        $data = $response['data'];

        if ($code === 0 || $data === null || $code === 401 || $code >= 500) {
            // Code 0: BackendApiClient has already logged why (unreachable, no secret, format 1)
            if ($code !== 0) {
                error_log(self::backendProblem($action, $code, $data));
            }
            return self::error(502, 'unavailable');
        }
        if (($data['ok'] ?? false) !== true) {
            $error = is_string($data['error'] ?? null) && preg_match('/^[a-z_]{1,40}$/', $data['error']) === 1
                ? $data['error'] : 'invalid_input';
            return self::error($code >= 400 && $code < 500 ? $code : 502, $error);
        }

        $body = ['ok' => true];
        foreach (self::RESPONSE_FIELDS[$action] as $field) {
            if (array_key_exists($field, $data)) {
                $body[$field] = $data[$field];
            }
        }
        if ($action === 'info' && $this->formTitle !== null && is_string($body['form_key'] ?? null)) {
            $body['form_title'] = ($this->formTitle)($body['form_key']);
        }

        return ['code' => 200, 'body' => $body];
    }

    /**
     * URL of a page next to the current script's directory, e.g. ".../anmeldung/sign.php" for a relay at
     * ".../anmeldung/api/signature.php". Behind a TLS-terminating proxy the scheme comes from HTTPS/X-Forwarded-Proto.
     *
     * @param array<string, mixed> $server
     */
    public static function siblingUrl(array $server, string $page, int $levelsUp = 1): string
    {
        $https = (!empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off')
            || strtolower((string)($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || strtolower((string)($server['REQUEST_SCHEME'] ?? '')) === 'https';
        $host = (string)($server['HTTP_HOST'] ?? 'localhost');
        $dir = dirname((string)($server['SCRIPT_NAME'] ?? '/'), $levelsUp + 1);
        $dir = rtrim(str_replace('\\', '/', $dir), '/');

        return ($https ? 'https' : 'http') . '://' . $host . $dir . '/' . ltrim($page, '/');
    }

    /** Title of a survey (string or localized object), '' if none. */
    public static function surveyTitle(string $surveyJson): string
    {
        $survey = json_decode($surveyJson, true);
        $title = is_array($survey) ? ($survey['title'] ?? '') : '';
        if (is_array($title)) {
            $title = $title['de'] ?? $title['default'] ?? (string)reset($title);
        }

        return is_string($title) ? trim(strip_tags($title)) : '';
    }

    /**
     * Log line for a backend answer the browser only sees as "unavailable", e.g.
     * "Signature handoff (create): backend HTTP 500 (server)". 401 = signature rejected (secret, clock),
     * 404 without JSON = endpoint missing (backend older than 3.2.0a or blocked by a proxy), 5xx = see the backend's error log.
     *
     * @param array<string, mixed>|null $data
     */
    public static function backendProblem(string $action, int $code, ?array $data): string
    {
        $detail = $data === null
            ? 'no JSON answer'
            : (is_string($data['error'] ?? null) && preg_match('/^[a-z_]{1,40}$/', $data['error']) === 1 ? $data['error'] : 'no error code');

        return sprintf('Signature handoff (%s): backend HTTP %d (%s)', $action, $code, $detail);
    }

    /** @return array{code: int, body: array{ok: false, error: string}} */
    private static function error(int $code, string $error): array
    {
        return ['code' => $code, 'body' => ['ok' => false, 'error' => $error]];
    }
}
