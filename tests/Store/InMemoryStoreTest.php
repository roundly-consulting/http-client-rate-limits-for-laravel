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
