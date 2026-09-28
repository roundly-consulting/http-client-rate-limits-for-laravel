<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use RoundlyConsulting\HttpClientRateLimits\Store\CacheStore;
use RoundlyConsulting\HttpClientRateLimits\Tests\NonLockingStore;

beforeEach(function () {
    config()->set('cache.default', 'array');
    Cache::store('array')->clear();
});

it('stores and returns hits', function () {
    $store = new CacheStore(store: 'array');

    expect($store->hits('john'))->toBe([]);

    $store->hit('john', 12345);

    expect($store->hits('john'))
        ->toBe([12345])
        ->and($store->hits('jane'))
        ->toBe([]);
});

it('shares hits between two instances on the same backend', function () {
    $a = new CacheStore(store: 'array');
    $b = new CacheStore(store: 'array');

    $a->hit('john', 10);
    $b->hit('john', 20);

    expect($b->hits('john'))->toBe([10, 20]);
});

it('returns hits since a given timestamp', function () {
    $store = new CacheStore(store: 'array');

    $store->hit('john', 10);
    $store->hit('john', 15);
    $store->hit('john', 20);

    expect($store->hitsSince('john', 15))->toBe([15, 20]);
});

it('clears recorded hits by timestamp', function () {
    $store = new CacheStore(store: 'array');

    $store->hit('john', 10);
    $store->hit('john', 15);
    $store->hit('john', 20);

    $store->clear('john', 15);

    expect($store->hits('john'))->toBe([20]);
});

it('forgets the key entirely when clearing removes every hit', function () {
    $store = new CacheStore(store: 'array');

    $store->hit('john', 10);
    $store->clear('john', 999);

    expect(Cache::store('array')->has($store->key('john')))->toBeFalse();
});

it('namespaces keys with a custom prefix', function () {
    $store = new CacheStore(store: 'array', prefix: 'custom');

    expect($store->key('john'))->toBe('custom:john');
});

it('trims hits older than the retention window on write', function () {
    $store = new CacheStore(store: 'array');

    $store->hit('john', 1_000);
    // A hit far beyond the retention window evicts the ancient entry.
    $newest = 1_000 + (90_001 * 1000);
    $store->hit('john', $newest);

    expect($store->hits('john'))->toBe([$newest]);
});

it('uses an atomic lock when the cache store supports one', function () {
    config()->set('cache.default', 'array'); // array store is a LockProvider

    $store = new CacheStore(store: 'array');

    $store->hit('john', 10);
    $store->hit('john', 20);

    expect($store->hits('john'))->toBe([10, 20]);
});

it('ignores a non-array cached payload', function () {
    Cache::store('array')->put('http-client-rate-limits:john', 'corrupt', 60);

    expect((new CacheStore(store: 'array'))->hits('john'))->toBe([]);
});

it('falls back to a best-effort write when the cache store has no lock support', function () {
    // NonLockingStore is deliberately not a LockProvider, exercising the
    // non-atomic write path.
    Cache::extend('non-locking', fn () => new Repository(new NonLockingStore));
    config()->set('cache.stores.non-locking', ['driver' => 'non-locking']);

    $store = new CacheStore(store: 'non-locking');

    $store->hit('john', 10);
    $store->hit('john', 20);
    $store->clear('john', 10);

    expect($store->hits('john'))->toBe([20]);
});

it('confirms the array store is a lock provider for the atomic path', function () {
    expect(new ArrayStore)->toBeInstanceOf(LockProvider::class);
});

it('records and reads penalties keeping the latest', function () {
    $store = new CacheStore(store: 'array');

    expect($store->penalizedUntil('john'))->toBeNull()
        ->and($store->penaltyKey('john'))->toBe('http-client-rate-limits:john:penalty');

    $store->penalizeUntil('john', 5_000);
    expect($store->penalizedUntil('john'))->toBe(5_000);

    $store->penalizeUntil('john', 4_000);
    expect($store->penalizedUntil('john'))->toBe(5_000);

    $store->penalizeUntil('john', 9_000);
    expect($store->penalizedUntil('john'))->toBe(9_000);
});

it('keeps hits oldest-first when one arrives out of order', function () {
    $store = new CacheStore(store: 'array');

    $store->hit('john', 20);
    $store->hit('john', 10);

    expect($store->hits('john'))->toBe([10, 20]);
});
