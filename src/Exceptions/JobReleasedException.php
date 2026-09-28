<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Exceptions;

use RoundlyConsulting\HttpClientRateLimits\Jobs\Middleware\HandlesRateLimitRelease;

/**
 * Control-flow signal thrown by the ReleaseDeferrer after it released `$job` back onto the
 * queue, unwinding the rate-limited call so the worker isn't blocked and the request is not
 * sent. The job is already re-queued: end the attempt quietly — the HandlesRateLimitRelease
 * job middleware does it for you — rather than letting it reach the worker, which would
 * report it and count it toward the job's `$maxExceptions`.
 *
 * @see HandlesRateLimitRelease
 */
final class JobReleasedException extends RateLimitException
{
    public function __construct(
        public readonly int $delaySeconds,
        public readonly string $key,
        public readonly ?object $job = null,
    ) {
        parent::__construct(sprintf(
            'Released job for [%s] back onto the queue with a %ds delay.',
            $key,
            $delaySeconds,
        ));
    }
}
