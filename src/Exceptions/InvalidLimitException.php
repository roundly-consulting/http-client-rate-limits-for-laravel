<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Exceptions;

/**
 * Thrown when a limit is given a budget no request could ever fit (fewer than one attempt
 * per window), rather than letting it pass requests or wait forever — or when
 * `Http::rateLimit([...])` is handed something it cannot turn into a limit.
 */
final class InvalidLimitException extends RateLimitException
{
    public static function maxAttempts(int $maxAttempts): self
    {
        return new self(sprintf(
            'A rate limit must allow at least 1 attempt per window, got [%d].',
            $maxAttempts,
        ));
    }

    public static function arrayEntry(mixed $entry): self
    {
        return new self(sprintf(
            'Http::rateLimit() takes a Limit, a RateLimit, a profile name or a per-minute integer in its array, got [%s].',
            get_debug_type($entry),
        ));
    }

    public static function emptyArray(): self
    {
        return new self('Http::rateLimit() needs at least one limit in its array.');
    }
}
