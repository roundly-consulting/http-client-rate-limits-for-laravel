<?php

declare(strict_types=1);

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\SleepDeferrer;
use RoundlyConsulting\HttpClientRateLimits\HttpClientRateLimitsServiceProvider;
use RoundlyConsulting\HttpClientRateLimits\RateLimitManager;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;

it('merges the package config', function (): void {
    expect(config('http-client-rate-limits.store'))->toBe(InMemoryStore::class)
        ->and(config('http-client-rate-limits.deferrer'))->toBe(SleepDeferrer::class)
        ->and(config('http-client-rate-limits.events_enabled'))->toBeTrue();
});

it('binds the rate limit manager as a singleton', function (): void {
    expect(app(RateLimitManager::class))->toBe(app(RateLimitManager::class));
});

it('registers the http client macro', function (): void {
    expect(PendingRequest::hasMacro('rateLimit'))->toBeTrue();
});

it('publishes the config under the http-client-rate-limits-config tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(
        HttpClientRateLimitsServiceProvider::class,
        'http-client-rate-limits-config',
    );

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0])->toEndWith('config/http-client-rate-limits.php');
});

it('publishes the migrations under the http-client-rate-limits-migrations tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(
        HttpClientRateLimitsServiceProvider::class,
        'http-client-rate-limits-migrations',
    );

    expect($paths)->toHaveCount(1)
        ->and(array_keys($paths)[0])->toEndWith('database/migrations');
});

it('contributes a section to the about command', function (): void {
    $this->artisan('about', ['--only' => 'http-client-rate-limits'])
        ->expectsOutputToContain('InMemoryStore')
        ->expectsOutputToContain('SleepDeferrer')
        ->expectsOutputToContain('ENABLED')
        ->assertSuccessful();
});

it('reports disabled events and configured profiles to the about command', function (): void {
    config()->set('http-client-rate-limits.events_enabled', false);
    config()->set('http-client-rate-limits.limiters', [
        'github' => ['rate' => 5, 'per' => 'second'],
    ]);

    $this->artisan('about', ['--only' => 'http-client-rate-limits'])
        ->expectsOutputToContain('OFF')
        ->assertSuccessful();
});

it('falls back to a default label when the store is not a class name', function (): void {
    config()->set('http-client-rate-limits.store', null);

    $this->artisan('about', ['--only' => 'http-client-rate-limits'])
        ->expectsOutputToContain('default')
        ->assertSuccessful();
});
