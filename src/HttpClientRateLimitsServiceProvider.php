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

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/http-client-rate-limits.php' => config_path('http-client-rate-limits.php'),
            ], 'http-client-rate-limits-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'http-client-rate-limits-migrations');
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

        PendingRequest::macro('rateLimit', function (RateLimit|Limit|int|string|array $limit, ?string $by = null): PendingRequest {
            // Resolve the manager per call so a swapped fake is honoured.
            $manager = app(RateLimitManager::class);

            $middleware = match (true) {
                $limit instanceof RateLimit => $limit,
                $limit instanceof Limit => RateLimit::make($limit),
                is_array($limit) => $manager->compound(array_values(array_filter(
                    $limit,
                    static fn (mixed $entry): bool => $entry instanceof Limit || $entry instanceof RateLimit,
                ))),
                is_string($limit) => $manager->profile($limit), // named profile
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
