<?php
declare(strict_types=1);

namespace App\Forms;

use App\Services\RateLimiter;

/**
 * Limits write actions of the form editor (save, publish, copy, ...) per admin user and address.
 *
 * Not a defence against a determined attacker (they would need a login first) but against runaway scripts, a stuck
 * double-submit loop and a stolen session hammering the history tables.
 *
 * Scopes count separately: the live preview checks a text after every change (SCOPE_PREVIEW, own limit), which must not
 * use up the allowance for saving and publishing (SCOPE_EDITOR).
 */
final class EditorRateLimit
{
    public const DEFAULT_MAX    = 60;
    public const DEFAULT_WINDOW = 60;

    public const SCOPE_EDITOR  = 'editor';
    public const SCOPE_PREVIEW = 'preview';
    /** Live preview: one check per change, several per second while someone types in the JSON tab. */
    public const DEFAULT_PREVIEW_MAX = 300;

    public function __construct(private readonly RateLimiter $limiter, private readonly string $scope = self::SCOPE_EDITOR)
    {
    }

    /**
     * Count one write action.
     *
     * @return int|null null = allowed; otherwise the number of seconds to wait
     */
    public function hit(string $user, string $ip): ?int
    {
        $id = $this->scope . ':' . $user . '@' . $ip;
        if ($this->limiter->isAllowed($id)) {
            return null;
        }
        return max(1, $this->limiter->getRetryAfter($id));
    }
}
