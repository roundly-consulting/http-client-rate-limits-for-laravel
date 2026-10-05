<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use RoundlyConsulting\Crypto\Hash\Digest;
use RoundlyConsulting\Crypto\Hash\HashAlgorithm;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\Models\RateLimitHit;
use RoundlyConsulting\HttpClientRateLimits\Models\RateLimitOwner;
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

it('keeps penalties separate from hits', function () {
    $store = new DatabaseStore;

    $store->hit('john', 1_000);
    $store->penalizeUntil('john', 9_000);

    // The penalty lives on the owner row and must not leak into the hit list.
    expect($store->hits('john'))->toBe([1_000])
        ->and($store->penalizedUntil('john'))->toBe(9_000)
        ->and(RateLimitOwner::query()->where('owner', 'john')->value('penalized_until'))->toBe(9_000);
});

it('persists hits through the eloquent model', function () {
    $store = new DatabaseStore;

    $store->hit('john', 1_000);

    expect(RateLimitHit::query()->where('owner', 'john')->count())->toBe(1);
});

it('prunes hits and idle owner rows older than the retention window on write', function () {
    $store = new DatabaseStore;
    $newest = 1_000 + (90_001 * 1000);

    $store->hit('john', 1_000);
    $store->hit('jane', 1_000);
    $store->penalizeUntil('jane', 5_000);              // passed by $newest: swept
    $store->penalizeUntil('joan', $newest + 60_000);   // still ahead of $newest: kept

    // A hit beyond the one-day (+1h) retention sweeps every expired hit row — for any
    // owner — and every owner row nothing used since whose penalty has passed.
    $store->hit('john', $newest);

    expect($store->hits('john'))->toBe([$newest])
        ->and($store->hits('jane'))->toBe([])
        ->and($store->penalizedUntil('jane'))->toBeNull()
        ->and($store->penalizedUntil('joan'))->toBe($newest + 60_000)
        ->and(RateLimitHit::withTrashed()->count())->toBe(1);
});

it('keeps hits inside the retention window on write', function () {
    $store = new DatabaseStore;

    $store->hit('john', 1_000);
    $store->hit('john', 1_000 + 86_400_000);

    expect($store->hits('john'))->toBe([1_000, 1_000 + 86_400_000]);
});

// Bug: both owner columns are varchar(255) and keys were written as given, so a `by` key past
// ~248 chars threw "Data too long" on every request (MySQL strict, Postgres) or was truncated,
// and the limit silently never matched (MySQL non-strict).
it('keeps an over-long key inside the owner columns, on a budget of its own', function () {
    $store = new DatabaseStore;
    $long = str_repeat('k', 300);
    $twin = str_repeat('k', 299).'j'; // the same first 255 characters

    expect($store->attempt([new Limit($long, 1, 'second')], 5_000)->allowed)->toBeTrue()
        ->and($store->attempt([new Limit($long, 1, 'second')], 5_100)->allowed)->toBeFalse()
        ->and($store->attempt([new Limit($twin, 1, 'second')], 5_100)->allowed)->toBeTrue()
        ->and($store->hits("{$long}:second"))->toBe([5_000])
        ->and($store->hits("{$twin}:second"))->toBe([5_100]);

    $store->penalizeUntil($long, 9_000);
    $store->clear("{$twin}:second", PHP_INT_MAX);

    expect($store->penalizedUntil($long))->toBe(9_000)
        ->and($store->penalizedUntil($twin))->toBeNull()
        ->and($store->hits("{$twin}:second"))->toBe([])
        ->and(RateLimitHit::query()->pluck('owner')->map(strlen(...))->max())->toBeLessThanOrEqual(255)
        ->and(RateLimitOwner::withTrashed()->pluck('owner')->map(strlen(...))->max())->toBeLessThanOrEqual(255);
});

it('never lets a short key pose as the stored form of a long one', function () {
    $store = new DatabaseStore;
    $long = str_repeat('k', 300);

    $store->penalizeUntil($long, 9_000);
    $store->penalizeUntil('sha256:'.new Digest(HashAlgorithm::Sha256)->hex($long), 20_000);

    expect($store->penalizedUntil($long))->toBe(9_000);
});
