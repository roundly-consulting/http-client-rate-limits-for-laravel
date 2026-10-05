<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Deferrer;

use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidDeferrerException;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\JobReleasedException;
use RoundlyConsulting\HttpClientRateLimits\Jobs\Middleware\HandlesRateLimitRelease;

/**
 * Inside a queued job, releasing the job back onto the queue is far cheaper than
 * blocking the worker with sleep(). Given the job (any object exposing Laravel's
 * release(int $seconds) method — e.g. one using Illuminate\Queue\InteractsWithQueue),
 * this deferrer re-queues it with the computed delay and throws a JobReleasedException
 * to unwind the current attempt without sending the request.
 *
 * Give the job the HandlesRateLimitRelease middleware (or catch the exception): it ends
 * the attempt cleanly, so the worker never sees the unwind as a job exception.
 *
 * The job is released at most once per deferrer. `Http::retry()` retries the unwind and
 * re-runs the limit, and each further defer only throws again: a second release would put a
 * second copy of the job on the database queue.
 *
 * @see HandlesRateLimitRelease
 */
final class ReleaseDeferrer implements Deferrer
{
    /** The delay (seconds) the job was released with, or null before its release. */
    private ?int $releasedSeconds = null;

    public function __construct(private readonly object $job)
    {
        if (! method_exists($this->job, 'release')) {
            throw new InvalidDeferrerException(sprintf(
                'ReleaseDeferrer requires a job exposing a release() method, got [%s].',
                $this->job::class,
            ));
        }
    }

    public function timestamp(): int
    {
        return now()->getTimestampMs();
    }

    public function defer(int $ms, string $key): void
    {
        if ($this->releasedSeconds === null) {
            // Laravel's release() delay is in seconds; round up so we never re-run early.
            $this->releasedSeconds = (int) ceil($ms / 1000);

            $this->job->release($this->releasedSeconds);
        }

        throw new JobReleasedException($this->releasedSeconds, $key, $this->job);
    }
}
