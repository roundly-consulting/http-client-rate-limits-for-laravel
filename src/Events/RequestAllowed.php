<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Events;

use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;

/**
 * Dispatched after a request was recorded and allowed through the limiter.
 */
final readonly class RequestAllowed
{
    public function __construct(
        public string $key,
        public int $hitsInWindow,
        public Timespan $timespan,
    ) {}
}
