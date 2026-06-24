<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\ServiceProvider;

final class HttpClientRateLimitsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/http-client-rate-limits.php',
            'http-client-rate-limits',
        );

        $this->app->singleton(RateLimitManager::class);
    }

    public function boot(): void
    {
        $this->registerMacro();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/http-client-rate-limits.php' => config_path('http-client-rate-limits.php'),
            ], 'http-client-rate-limits-config');
        }
    }

    /**
     * Add a discoverable `Http::rateLimit(...)` macro. Guarded because macros
     * are process-global and the provider may boot more than once in tests.
     */
    private function registerMacro(): void
    {
        if (PendingRequest::hasMacro('rateLimit')) {
            return;
        }

        PendingRequest::macro('rateLimit', function (RateLimit|Limit|int $limit, ?string $by = null): PendingRequest {
            $middleware = match (true) {
                $limit instanceof RateLimit => $limit,
                $limit instanceof Limit => RateLimit::make($limit),
                default => RateLimit::perMinute($limit), // int shorthand = per-minute
            };

            if ($by !== null) {
                $middleware->by($by);
            }

            /** @var PendingRequest $this */
            return $this->withMiddleware($middleware);
        });
    }
}
