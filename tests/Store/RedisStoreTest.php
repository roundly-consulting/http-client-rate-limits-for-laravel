<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use RoundlyConsulting\HttpClientRateLimits\Store\RedisStore;

// Runs in CI against a real Redis service and is skipped automatically when no
// Redis connection is reachable (e.g. on a developer machine without Redis).
uses()->group('redis');

beforeEach(function () {
    try {
        Redis::connection()->ping();
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis connection is not available: '.$e->getMessage());
    }

    $store = new RedisStore;

    Redis::connection()->del($store->key('john'));
    Redis::connection()->del($store->key('jane'));
    Redis::connection()->del($store->penaltyKey('john'));
    Redis::connection()->del($store->penaltyKey('jane'));
});

it('stores and returns hits', function () {
    $store = new RedisStore;

    expect($store->hits('john'))->toBe([]);

    $store->hit('john', 12345);

    expect($store->hits('john'))
        ->toBe([12345])
        ->and($store->hits('jane'))
        ->toBe([]);
});

it('returns hits since given timestamp', function () {
    $store = new RedisStore;

    $store->hit('john', 10);
    $store->hit('john', 15);
    $store->hit('john', 20);

    $store->hit('jane', 15);

    expect($store->hitsSince('john', 10))
        ->toBe([10, 15, 20])
        ->and($store->hitsSince('jane', 10))
        ->toBe([15])
        ->and($store->hitsSince('john', 15))
        ->toBe([15, 20]);
});

it('sets a bounded ttl on the key when recording a hit', function () {
    $store = new RedisStore;

    $store->hit('john', now()->getTimestampMs());

    expect(Redis::connection()->ttl($store->key('john')))->toBeGreaterThan(0);
});

it('clears recorded hits by timestamp', function () {
    $store = new RedisStore;

    $store->hit('john', 10);
    $store->hit('john', 15);
    $store->hit('john', 20);
    $store->hit('jane', 10);

    $store->clear('john', 15);

    expect($store->hits('john'))
        ->toBe([20])
        ->and($store->hits('jane'))
        ->toBe([10]);
});

it('records and reads penalties keeping the latest', function () {
    $store = new RedisStore;

    expect($store->penalizedUntil('john'))->toBeNull()
        ->and($store->penaltyKey('john'))->toBe('http-client-rate-limits:john:penalty');

    $store->penalizeUntil('john', 5_000);
    expect($store->penalizedUntil('john'))->toBe(5_000);

    $store->penalizeUntil('john', 4_000);
    expect($store->penalizedUntil('john'))->toBe(5_000);

    $store->penalizeUntil('john', 9_000);
    expect($store->penalizedUntil('john'))->toBe(9_000);
});
