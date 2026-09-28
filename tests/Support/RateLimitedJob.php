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
 * The README's queued-job example, verbatim in shape: rate-limits by releasing itself.
 */
final class RateLimitedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 10;

    public int $maxExceptions = 2;

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new HandlesRateLimitRelease];
    }

    public function handle(): void
    {
        $rateLimit = RateLimits::releasingJob($this)->perHour(1)->by('job-demo');

        Http::withMiddleware($rateLimit)->get('https://api.example.com/things');
    }
}
