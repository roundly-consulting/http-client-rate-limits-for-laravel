<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Deferrer;

use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidDeferrerException;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\JobReleasedException;

/**
 * Inside a queued job, releasing the job back onto the queue is far cheaper than
 * blocking the worker with sleep(). Given the job (any object exposing Laravel's
 * release(int $seconds) method — e.g. one using Illuminate\Queue\InteractsWithQueue),
 * this deferrer re-queues it with the computed delay and throws a JobReleasedException
 * to unwind the current attempt.
 */
final class ReleaseDeferrer implements Deferrer
{
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
        // Laravel's release() delay is in seconds; round up so we never re-run early.
        $seconds = (int) ceil($ms / 1000);

        $this->job->release($seconds);

        throw new JobReleasedException($seconds);
    }
}
