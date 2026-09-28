<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;
use RoundlyConsulting\HttpClientRateLimits\RateLimit;
use RoundlyConsulting\HttpClientRateLimits\RateLimitManager;
use RoundlyConsulting\HttpClientRateLimits\Store\CacheStore;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\HttpClientRateLimits\Tests\TestDeferrer;

/*
 * The default store must be shared by every rate limit the app builds in the
 * process: a fresh InMemoryStore per `Http::rateLimit()` call would start every
 * request with an empty window, so the limit could never be reached.
 */

afterEach(fn () => RateLimit::use());

it('accumulates hits across separate Http::rateLimit calls with the default store', function () {
    Sleep::fake();
    Http::fake(['*' => Http::response('ok')]);

    Http::rateLimit(1)->get('https://api.example.com/one');
    Http::rateLimit(1)->get('https://api.example.com/two');

    // 1/min: the second, separately built limit sees the first call's hit and waits.
    Sleep::assertSleptTimes(1);
});

it('hands every manager-built rate limit the same default store', function () {
    $manager = app(RateLimitManager::class);

    $store = $manager->perMinute(1)->getStore();

    expect($store)->toBeInstanceOf(InMemoryStore::class)
        ->and($manager->perSecond(5)->getStore())->toBe($store)
        ->and($manager->compound([])->getStore())->toBe($store)
        ->and(RateLimits::store())->toBe($store);
});

it('builds RateLimit factories on the shared default store', function () {
    expect(RateLimit::perMinute(1)->getStore())->toBe(RateLimit::perHour(5)->getStore())
        ->and(RateLimit::perDay(1)->getStore())->toBe(app(RateLimitManager::class)->perSecond(2)->getStore());
});

it('shares the default store when only a default deferrer is set', function () {
    RateLimit::use(defaultDeferrer: new TestDeferrer);

    expect(RateLimit::perMinute(1)->getStore())->toBe(RateLimit::perSecond(5)->getStore());
});

it('resolves a new shared store when the configured store changes', function () {
    $memory = RateLimit::perMinute(1)->getStore();

    config()->set('http-client-rate-limits.store', CacheStore::class);

    $cache = RateLimit::perMinute(1)->getStore();

    expect($cache)->toBeInstanceOf(CacheStore::class)
        ->and($cache)->not->toBe($memory)
        ->and(RateLimit::perSecond(3)->getStore())->toBe($cache);
});

it('honours the cache store settings when only a default deferrer is set', function () {
    RateLimit::use(defaultDeferrer: new TestDeferrer);
    config()->set('http-client-rate-limits.store', CacheStore::class);
    config()->set('http-client-rate-limits.cache_store', 'array');
    config()->set('http-client-rate-limits.cache_prefix', 'custom');

    $store = RateLimit::perMinute(5)->getStore();

    expect($store)->toBeInstanceOf(CacheStore::class)
        ->and($store->key('john'))->toBe('custom:john');
});

// Regression: a using*() copy cloned the resolved-store map, so its limits on the default
// in-memory store started from an empty window every time.
it('shares the default store when only the deferrer is overridden', function () {
    $manager = RateLimits::usingDeferrer(new TestDeferrer);

    expect($manager->perMinute(1)->getStore())->toBe($manager->perSecond(5)->getStore())
        ->and($manager->perMinute(1)->getStore())->toBe(RateLimits::store());
});
