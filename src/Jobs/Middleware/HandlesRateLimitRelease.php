<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Jobs\Middleware;

use Closure;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\JobReleasedException;

/**
 * Job middleware for jobs that rate-limit with `RateLimits::releasingJob($this)`. When the
 * limiter releases the job back onto the queue, it unwinds the attempt with a
 * JobReleasedException; this ends the attempt there, as a normal return, so the worker
 * neither reports it nor fires JobExceptionOccurred nor counts it toward `$maxExceptions`.
 *
 * Only this job's own release is swallowed: an exception for another job (one it ran
 * synchronously) keeps propagating, because this job was not re-queued.
 *
 *     public function middleware(): array
 *     {
 *         return [new HandlesRateLimitRelease];
 *     }
 */
final class HandlesRateLimitRelease
{
    public function handle(object $job, Closure $next): mixed
    {
        try {
            return $next($job);
        } catch (JobReleasedException $exception) {
            if ($exception->job !== $job) {
                throw $exception;
            }

            return null;
        }
    }
}
