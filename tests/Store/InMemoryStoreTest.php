<?php

declare(strict_types=1);

use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;

it('stores and returns hits', function () {
    $store = new InMemoryStore;

    expect($store->hits('john'))->toBe([]);

    $store->hit('john', 12345);

    expect($store->hits('john'))
        ->toBe([12345])
        ->and($store->hits('jane'))
        ->toBe([]);
});

it('returns hits since given timestamp', function () {
    $store = new InMemoryStore;

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

it('clears recorded hits by timestamp', function () {
    $store = new InMemoryStore;

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
    $store = new InMemoryStore;

    expect($store->penalizedUntil('john'))->toBeNull();

    $store->penalizeUntil('john', 5_000);
    expect($store->penalizedUntil('john'))->toBe(5_000);

    // A later penalty wins; an earlier one is ignored.
    $store->penalizeUntil('john', 8_000);
    expect($store->penalizedUntil('john'))->toBe(8_000);

    $store->penalizeUntil('john', 6_000);
    expect($store->penalizedUntil('john'))->toBe(8_000)
        ->and($store->penalizedUntil('jane'))->toBeNull();
});
