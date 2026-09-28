<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Exceptions;

/**
 * Thrown when a limit is given a budget no request could ever fit (fewer than one attempt
 * per window), rather than letting it pass requests or wait forever.
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
}
