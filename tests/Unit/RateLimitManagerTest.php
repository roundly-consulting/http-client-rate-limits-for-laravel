<?php

declare(strict_types=1);

use RoundlyConsulting\HttpClientRateLimits\Deferrer\SleepDeferrer;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidDeferrerException;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidStoreException;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\UnknownLimiterProfileException;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\RateLimit;
use RoundlyConsulting\HttpClientRateLimits\RateLimitManager;
use RoundlyConsulting\HttpClientRateLimits\Store\CacheStore;
use RoundlyConsulting\HttpClientRateLimits\Store\DatabaseStore;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\HttpClientRateLimits\Store\RedisStore;
use RoundlyConsulting\HttpClientRateLimits\Tests\TestDeferrer;
use RoundlyConsulting\HttpClientRateLimits\Tests\TestStore;

it('is resolved as a singleton from the container', function () {
    expect(app(RateLimitManager::class))->toBe(app(RateLimitManager::class));
});

it('builds rate limits for every window', function () {
    $manager = app(RateLimitManager::class);

    expect($manager->perSecond(5)->getTimespan())->toBe('second')
        ->and($manager->perMinute(5)->getTimespan())->toBe('minute')
        ->and($manager->perHour(5)->getTimespan())->toBe('hour')
        ->and($manager->perDay(5)->getTimespan())->toBe('day')
        ->and($manager->perMinute(5))->toBeInstanceOf(RateLimit::class);
});

it('resolves the default in-memory store and sleep deferrer', function () {
    $rateLimit = app(RateLimitManager::class)->perMinute(5);

    expect($rateLimit->getStore())->toBeInstanceOf(InMemoryStore::class)
        ->and($rateLimit->getDeferrer())->toBeInstanceOf(SleepDeferrer::class);
});

it('resolves a redis store with the configured connection', function () {
    config()->set('http-client-rate-limits.store', RedisStore::class);
    config()->set('http-client-rate-limits.redis_connection', 'cache');

    expect(app(RateLimitManager::class)->perMinute(5)->getStore())
        ->toBeInstanceOf(RedisStore::class);
});

it('resolves a cache store with the configured store name and prefix', function () {
    config()->set('http-client-rate-limits.store', CacheStore::class);
    config()->set('http-client-rate-limits.cache_store', 'array');
    config()->set('http-client-rate-limits.cache_prefix', 'custom');

    $store = app(RateLimitManager::class)->perMinute(5)->getStore();

    expect($store)->toBeInstanceOf(CacheStore::class)
        ->and($store->key('john'))->toBe('custom:john');
});

it('honours fluent store and deferrer overrides', function () {
    $rateLimit = app(RateLimitManager::class)
        ->usingStore($store = new TestStore)
        ->usingDeferrer($deferrer = new TestDeferrer)
        ->perMinute(5);

    expect($rateLimit->getStore())->toBe($store)
        ->and($rateLimit->getDeferrer())->toBe($deferrer);
});

it('throws when the configured store is invalid', function () {
    config()->set('http-client-rate-limits.store', stdClass::class);

    app(RateLimitManager::class)->perMinute(5);
})->throws(InvalidStoreException::class);

it('throws when the configured deferrer is invalid', function () {
    config()->set('http-client-rate-limits.deferrer', stdClass::class);

    app(RateLimitManager::class)->perMinute(5);
})->throws(InvalidDeferrerException::class);

it('resolves a database store from config', function () {
    config()->set('http-client-rate-limits.store', DatabaseStore::class);

    expect(app(RateLimitManager::class)->perMinute(5)->getStore())
        ->toBeInstanceOf(DatabaseStore::class);
});

it('builds a rate limit from a named profile', function () {
    config()->set('http-client-rate-limits.limiters', [
        'github' => ['rate' => 5, 'per' => 'second', 'by' => 'gh'],
    ]);

    $rateLimit = app(RateLimitManager::class)->profile('github');

    expect($rateLimit)->toBeInstanceOf(RateLimit::class)
        ->getMaxAttempts()->toBe(5)
        ->getTimespan()->toBe('second')
        ->getKey()->toBe('gh');
});

it('throws for an unknown profile name', function () {
    config()->set('http-client-rate-limits.limiters', []);

    app(RateLimitManager::class)->profile('missing');
})->throws(UnknownLimiterProfileException::class);

it('throws when the profile config is not an array', function () {
    config()->set('http-client-rate-limits.limiters', ['bad' => 'nope']);

    app(RateLimitManager::class)->profile('bad');
})->throws(UnknownLimiterProfileException::class);

it('builds a compound rate limit from limits and rate limits', function () {
    $rateLimit = app(RateLimitManager::class)->compound([
        new Limit(maxAttempts: 5, timespan: 'second'),
        RateLimits::perMinute(100),
    ]);

    expect($rateLimit->getLimiter()->getLimits())->toHaveCount(2)
        ->and($rateLimit->getLimiter()->getLimit()->getTimespan())->toBe('second');
});

it('builds a compound rate limit defaulting to a global limit when empty', function () {
    $rateLimit = app(RateLimitManager::class)->compound([]);

    expect($rateLimit->getLimiter()->getLimits())->toHaveCount(1);
});
