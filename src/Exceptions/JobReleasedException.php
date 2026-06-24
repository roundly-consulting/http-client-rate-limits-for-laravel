<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Exceptions;

/**
 * Control-flow signal thrown by the ReleaseDeferrer after it releases the
 * current queued job back onto the queue, unwinding the rate-limited call so
 * the worker isn't blocked. Catch it to swallow the unwind if you handle the
 * release yourself; otherwise let it bubble — the job is already re-queued.
 */
final class JobReleasedException extends RateLimitException
{
    public function __construct(
        public readonly int $delaySeconds,
        public readonly string $key = 'global',
    ) {
        parent::__construct(sprintf(
            'Released job for [%s] back onto the queue with a %ds delay.',
            $key,
            $delaySeconds,
        ));
    }
}
