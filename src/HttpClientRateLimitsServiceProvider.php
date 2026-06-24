<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits;

use Illuminate\Support\ServiceProvider;

final class HttpClientRateLimitsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/http-client-rate-limits.php',
            'http-client-rate-limits',
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/http-client-rate-limits.php' => config_path('http-client-rate-limits.php'),
            ], 'http-client-rate-limits-config');
        }
    }
}
