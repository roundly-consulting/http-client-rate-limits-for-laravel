<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;
use RoundlyConsulting\HttpClientRateLimits\Jobs\Middleware\HandlesRateLimitRelease;

/**
 * The queued-job example plus Laravel's `Http::retry()`, whose retries re-run the rate-limit
 * middleware on every try.
 */
final class RetryingRateLimitedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 10;

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new HandlesRateLimitRelease];
    }

    public function handle(): void
    {
        $rateLimit = RateLimits::releasingJob($this)->perHour(1)->by('retry-demo');

        Http::retry(3, 0)->withMiddleware($rateLimit)->get('https://api.example.com/things');
    }
}
