<?php
declare(strict_types=1);

namespace App\Services;

use App\Forms\Identifiers;
use App\Repositories\SignatureHandoffRepository;
use App\Utils\DataFormatter;

/**
 * Signature on a smartphone via QR code (3.2.0a, see docs/entwicklung/plans/PLAN-3.2.0a.md).
 *
 * The computer creates a session and keeps the read key; the QR code carries the write token.
 * The phone may only ask for the session (info) and deliver one PNG (submit); the computer may only
 * pick it up (poll), once, after which the session is gone. Both secrets are stored as SHA-256 only.
 * Times leave this class as remaining seconds (expires_in), never as a point in time: the phone's
 * clock may be wrong.
 *
 * Results are arrays with 'ok' => bool and, on failure, 'error' => one of the ERROR_* codes.
 */
class SignatureHandoffService
{
    public const TTL_SECONDS = 600;
    public const MAX_OPEN_PER_TENANT = 200;
    /** Smaller than DataFormatter::MAX_IMAGE_DATA_URI_LENGTH: a signature is a few KB. */
    public const MAX_PNG_LENGTH = 200000;
    public const MAX_SIGN_URL_LENGTH = 500;
    public const MIN_SIZE = 50;
    public const MAX_SIZE = 2000;

    public const ERROR_INVALID_INPUT = 'invalid_input';
    public const ERROR_LIMIT = 'limit';
    /** Unknown id, wrong token or another tenant: deliberately indistinguishable. */
    public const ERROR_INVALID = 'invalid';
    public const ERROR_EXPIRED = 'expired';
    public const ERROR_USED = 'used';
    public const ERROR_BAD_IMAGE = 'bad_image';

    public const STATUS_OPEN = 'open';
    public const STATUS_SCANNED = 'scanned';
    public const STATUS_SIGNED = 'signed';
    public const STATUS_EXPIRED = 'expired';

    private const PNG_PREFIX = 'data:image/png;base64,';
    private const PNG_MAGIC = "\x89PNG\r\n\x1a\n";

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param (\Closure(): int)|null $clock current Unix time (tests) */
    public function __construct(
        private SignatureHandoffRepository $repository,
        ?\Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * New session for one signature field. Expired sessions of the tenant are removed first.
     *
     * @return array{ok: true, id: string, read_key: string, qr_url: string, expires_in: int}|array{ok: false, error: string}
     */
    public function create(string $formKey, string $fieldName, string $fieldTitle, int $width, int $height, string $signUrl): array
    {
        $fieldName = trim($fieldName);
        $fieldTitle = trim($fieldTitle);
        if (
            !Identifiers::isValidFormKey($formKey)
            || $fieldName === '' || mb_strlen($fieldName) > 200
            || mb_strlen($fieldTitle) > 255
            || $width < self::MIN_SIZE || $width > self::MAX_SIZE
            || $height < self::MIN_SIZE || $height > self::MAX_SIZE
            || !self::isValidSignUrl($signUrl)
        ) {
            return ['ok' => false, 'error' => self::ERROR_INVALID_INPUT];
        }

        $now = ($this->clock)();
        $this->repository->deleteExpired($now);
        if ($this->repository->countOpen($now) >= self::MAX_OPEN_PER_TENANT) {
            return ['ok' => false, 'error' => self::ERROR_LIMIT];
        }

        $id = bin2hex(random_bytes(16));
        $writeToken = bin2hex(random_bytes(32));
        $readKey = bin2hex(random_bytes(32));

        $this->repository->insert([
            'id' => $id,
            'form_key' => $formKey,
            'field_name' => $fieldName,
            'field_title' => $fieldTitle !== '' ? $fieldTitle : $fieldName,
            'width' => $width,
            'height' => $height,
            'write_hash' => self::hash($writeToken),
            'read_hash' => self::hash($readKey),
            'created_at' => $now,
            'expires_at' => $now + self::TTL_SECONDS,
        ]);

        return [
            'ok' => true,
            'id' => $id,
            'read_key' => $readKey,
            // Fragment, not query: never in server logs or the Referer
            'qr_url' => $signUrl . '#s=' . $id . '&t=' . $writeToken,
            'expires_in' => self::TTL_SECONDS,
        ];
    }

    /**
     * What the phone shows before signing; marks the session as scanned.
     *
     * @return array{ok: true, form_key: string, field_name: string, field_title: string, width: int, height: int, expires_in: int}|array{ok: false, error: string}
     */
    public function info(string $id, string $writeToken): array
    {
        $session = $this->findWithToken($id, $writeToken, 'write_hash');
        if (isset($session['error'])) {
            return ['ok' => false, 'error' => $session['error']];
        }
        if ($session['status'] === self::STATUS_SIGNED) {
            return ['ok' => false, 'error' => self::ERROR_USED];
        }

        $now = ($this->clock)();
        $this->repository->markScanned($id, $now);

        return [
            'ok' => true,
            'form_key' => $session['form_key'],
            'field_name' => $session['field_name'],
            'field_title' => $session['field_title'],
            'width' => $session['width'],
            'height' => $session['height'],
            'expires_in' => max(0, $session['expires_at'] - $now),
        ];
    }

    /**
     * Delivers the signature from the phone (once).
     *
     * @return array{ok: true}|array{ok: false, error: string}
     */
    public function submit(string $id, string $writeToken, string $pngDataUri): array
    {
        $session = $this->findWithToken($id, $writeToken, 'write_hash');
        if (isset($session['error'])) {
            return ['ok' => false, 'error' => $session['error']];
        }
        if ($session['status'] === self::STATUS_SIGNED) {
            return ['ok' => false, 'error' => self::ERROR_USED];
        }

        $png = self::normalizePng($pngDataUri);
        if ($png === null) {
            return ['ok' => false, 'error' => self::ERROR_BAD_IMAGE];
        }

        // Conditional update: of two concurrent submits only one wins
        if (!$this->repository->storeSignature($id, $png, ($this->clock)())) {
            return ['ok' => false, 'error' => self::ERROR_USED];
        }

        return ['ok' => true];
    }

    /**
     * State for the computer; hands out the signature exactly once and then deletes the session.
     * An expired session reports STATUS_EXPIRED (and is deleted), a wrong key ERROR_INVALID.
     *
     * @return array{ok: true, status: string, expires_in: int, png?: string}|array{ok: false, error: string}
     */
    public function poll(string $id, string $readKey): array
    {
        $session = $this->findWithToken($id, $readKey, 'read_hash', allowExpired: true);
        if (isset($session['error'])) {
            return ['ok' => false, 'error' => $session['error']];
        }

        $now = ($this->clock)();
        if ($session['expires_at'] <= $now) {
            $this->repository->delete($id);
            return ['ok' => true, 'status' => self::STATUS_EXPIRED, 'expires_in' => 0];
        }

        if ($session['status'] === self::STATUS_SIGNED && $session['png'] !== null) {
            // Only the request that actually deletes the row gets the image
            if (!$this->repository->delete($id)) {
                return ['ok' => false, 'error' => self::ERROR_INVALID];
            }
            return ['ok' => true, 'status' => self::STATUS_SIGNED, 'expires_in' => 0, 'png' => $session['png']];
        }

        return ['ok' => true, 'status' => $session['status'], 'expires_in' => $session['expires_at'] - $now];
    }

    /** Removes expired sessions of the current tenant. */
    public function cleanup(): int
    {
        return $this->repository->deleteExpired(($this->clock)());
    }

    /**
     * Checks the signature image and, with GD, re-encodes it (drops metadata and trailing data).
     * Returns the data URI to store, or null for anything that is not a sane PNG.
     */
    public static function normalizePng(string $dataUri): ?string
    {
        if (strlen($dataUri) > self::MAX_PNG_LENGTH || !str_starts_with($dataUri, self::PNG_PREFIX)) {
            return null;
        }
        if (!DataFormatter::isImageDataUri($dataUri)) {
            return null;
        }

        $bytes = base64_decode(substr($dataUri, strlen(self::PNG_PREFIX)), true);
        if ($bytes === false || !str_starts_with($bytes, self::PNG_MAGIC)) {
            return null;
        }

        if (!function_exists('imagecreatefromstring')) {
            return $dataUri;
        }

        $size = @getimagesizefromstring($bytes);
        if (
            $size === false || ($size[2] ?? null) !== IMAGETYPE_PNG
            || $size[0] < 1 || $size[0] > self::MAX_SIZE * 2
            || $size[1] < 1 || $size[1] > self::MAX_SIZE * 2
        ) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        imagesavealpha($image, true);
        ob_start();
        $written = imagepng($image);
        $clean = (string)ob_get_clean();
        if (!$written || $clean === '') {
            return null;
        }

        $result = self::PNG_PREFIX . base64_encode($clean);

        return strlen($result) <= self::MAX_PNG_LENGTH ? $result : null;
    }

    /** Absolute http(s) URL without fragment; the fragment is ours (#s=…&t=…). */
    public static function isValidSignUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_SIGN_URL_LENGTH || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['fragment'])
            && !isset($parts['user']) && !isset($parts['pass']);
    }

    private static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * @return array{id: string, form_key: string, field_name: string, field_title: string, width: int, height: int,
     *               write_hash: string, read_hash: string, png: ?string, status: string, created_at: int, expires_at: int}|array{error: string}
     */
    private function findWithToken(string $id, string $secret, string $hashColumn, bool $allowExpired = false): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1 || preg_match('/^[a-f0-9]{64}$/D', $secret) !== 1) {
            return ['error' => self::ERROR_INVALID];
        }

        $session = $this->repository->find($id);
        if ($session === null || !hash_equals($session[$hashColumn], self::hash($secret))) {
            return ['error' => self::ERROR_INVALID];
        }
        if (!$allowExpired && $session['expires_at'] <= ($this->clock)()) {
            return ['error' => self::ERROR_EXPIRED];
        }

        return $session;
    }
}
