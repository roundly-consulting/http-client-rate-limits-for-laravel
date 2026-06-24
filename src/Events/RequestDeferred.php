<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Events;

use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;

/**
 * Dispatched right before a request is paused because the limit was reached.
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
