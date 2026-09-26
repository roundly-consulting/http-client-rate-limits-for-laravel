<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use RoundlyConsulting\HttpClientRateLimits\Models\RateLimitHit;
use RoundlyConsulting\HttpClientRateLimits\Store\DatabaseStore;

uses(RefreshDatabase::class);

it('stores and returns hits', function () {
    $store = new DatabaseStore;

    expect($store->hits('john'))->toBe([]);

    $store->hit('john', 12345);

    expect($store->hits('john'))->toBe([12345])
        ->and($store->hits('jane'))->toBe([]);
});

it('returns hits since a given timestamp', function () {
    $store = new DatabaseStore;

    $store->hit('john', 10);
    $store->hit('john', 20);
    $store->hit('john', 30);

    expect($store->hitsSince('john', 20))->toBe([20, 30])
        ->and($store->hitsSince('john', 0))->toBe([10, 20, 30]);
});

it('clears recorded hits by timestamp', function () {
    $store = new DatabaseStore;

    $store->hit('john', 10);
    $store->hit('john', 15);
    $store->hit('john', 20);
    $store->hit('jane', 10);

    $store->clear('john', 15);

    expect($store->hits('john'))->toBe([20])
        ->and($store->hits('jane'))->toBe([10]);
});

it('records and reads penalties keeping the latest', function () {
    $store = new DatabaseStore;

    expect($store->penalizedUntil('john'))->toBeNull();

    $store->penalizeUntil('john', 5_000);
    expect($store->penalizedUntil('john'))->toBe(5_000);

    $store->penalizeUntil('john', 4_000);
    expect($store->penalizedUntil('john'))->toBe(5_000);

    $store->penalizeUntil('john', 9_000);
    expect($store->penalizedUntil('john'))->toBe(9_000)
        ->and($store->penalizedUntil('jane'))->toBeNull();
});

it('keeps penalty rows separate from hit rows', function () {
    $store = new DatabaseStore;

    $store->hit('john', 1_000);
    $store->penalizeUntil('john', 9_000);

    // The penalty row must not leak into the hit list.
    expect($store->hits('john'))->toBe([1_000])
        ->and($store->penalizedUntil('john'))->toBe(9_000);
});

it('persists hits through the eloquent model', function () {
    $store = new DatabaseStore;

    $store->hit('john', 1_000);

    expect(RateLimitHit::query()->where('owner', 'john')->whereNotNull('hit_at')->count())->toBe(1);
});

it('prunes hits older than the retention window on write', function () {
    $store = new DatabaseStore;

    $store->hit('john', 1_000);
    $store->hit('jane', 1_000);
    $store->penalizeUntil('jane', 5_000);

    // A hit beyond the one-day (+1h) retention sweeps every expired hit row —
    // for any owner — so the table stays bounded; penalty rows are kept.
    $newest = 1_000 + (90_001 * 1000);
    $store->hit('john', $newest);

    expect($store->hits('john'))->toBe([$newest])
        ->and($store->hits('jane'))->toBe([])
        ->and($store->penalizedUntil('jane'))->toBe(5_000)
        ->and(RateLimitHit::withTrashed()->whereNotNull('hit_at')->count())->toBe(1);
});

it('keeps hits inside the retention window on write', function () {
    $store = new DatabaseStore;

    $store->hit('john', 1_000);
    $store->hit('john', 1_000 + 86_400_000);

    expect($store->hits('john'))->toBe([1_000, 1_000 + 86_400_000]);
});
