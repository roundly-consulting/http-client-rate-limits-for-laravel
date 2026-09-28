<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Events;

/**
 * Dispatched after a limit's recorded hits were cleared with `RateLimit::reset()`.
 */
final readonly class RateLimitReset
{
    public function __construct(
        public string $key,
    ) {}
}
