<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Events;

use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;

/**
 * Dispatched right before a request is paused because the limit was reached.
 * `key`, `hitsInWindow` and `timespan` describe the window that forced the wait:
 * `hitsInWindow` is the number of requests recorded in it at that moment (0 when
 * an adaptive server penalty alone caused the wait).
 */
final readonly class RequestDeferred
{
    public function __construct(
        public string $key,
        public int $delayMs,
        public int $hitsInWindow,
        public Timespan $timespan,
    ) {}
}
