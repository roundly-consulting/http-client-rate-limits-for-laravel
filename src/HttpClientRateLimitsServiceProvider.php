<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits;

use Illuminate\Http\Client\PendingRequest;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;

final class HttpClientRateLimitsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('http-client-rate-limits')
            ->hasConfigFile()
            ->hasMigrations()
            ->contributesToAbout(static function (): array {
                /** @var array<string, mixed> $limiters */
                $limiters = config('http-client-rate-limits.limiters', []);

                return [
                    'Store' => self::classLabel(config('http-client-rate-limits.store')),
                    'Deferrer' => self::classLabel(config('http-client-rate-limits.deferrer')),
                    'Limiter profiles' => (string) count($limiters),
                    'Events' => config('http-client-rate-limits.events_enabled') === false ? 'OFF' : 'ENABLED',
                ];
            });
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(RateLimitManager::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerMacro();
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

    /**
     * The short class name of a configured implementation, for `php artisan about`.
     */
    private static function classLabel(mixed $value): string
    {
        return is_string($value) && $value !== '' ? class_basename($value) : 'default';
    }
}
