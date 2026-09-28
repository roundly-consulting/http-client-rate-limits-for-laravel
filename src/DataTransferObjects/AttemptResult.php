<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\DataTransferObjects;

use RoundlyConsulting\HttpClientRateLimits\Limit;

/**
 * The outcome of one atomic `Store::attempt()`: either the request was recorded in every
 * window, or nothing was recorded and `delayMs` says how long until it could be — with the
 * window that forced the wait and the number of hits it held at that moment.
 */
final readonly class AttemptResult
{
    public function __construct(
        public bool $allowed,
        public int $delayMs = 0,
        public ?Limit $limit = null,
        public int $hitsInWindow = 0,
    ) {}

    public static function allowed(): self
    {
        return new self(allowed: true);
    }

    public static function deferred(int $delayMs, Limit $limit, int $hitsInWindow): self
    {
        return new self(
            allowed: false,
            delayMs: $delayMs,
            limit: $limit,
            hitsInWindow: $hitsInWindow,
        );
    }
}
