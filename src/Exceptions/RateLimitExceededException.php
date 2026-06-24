<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Exceptions;

/**
 * Thrown when a computed defer would exceed a limit's configured max-wait
 * ceiling, so callers can fail fast instead of blocking for too long.
 */
final class RateLimitExceededException extends RateLimitException
{
    public function __construct(
        string $message,
        public readonly string $key = 'global',
        public readonly int $delayMs = 0,
        public readonly int $maxWaitMs = 0,
    ) {
        parent::__construct($message);
    }

    public static function for(string $key, int $delayMs, int $maxWaitMs): self
    {
        return new self(
            sprintf(
                'Rate limit for [%s] would defer for %dms, exceeding the max wait of %dms.',
                $key,
                $delayMs,
                $maxWaitMs,
            ),
            $key,
            $delayMs,
            $maxWaitMs,
        );
    }
}
