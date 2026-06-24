<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Exceptions;

/**
 * Thrown when a named limiter profile is referenced but not defined under
 * the [http-client-rate-limits.limiters] config key.
 */
final class UnknownLimiterProfileException extends RateLimitException
{
    public static function for(string $name): self
    {
        return new self(sprintf(
            'No limiter profile named [%s] is defined in [http-client-rate-limits.limiters].',
            $name,
        ));
    }
}
