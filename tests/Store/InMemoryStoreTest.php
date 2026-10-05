<?php

declare(strict_types=1);

use RoundlyConsulting\HttpClientRateLimits\Limit;
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

it('drops hits older than the retention window on write', function () {
    $store = new InMemoryStore;

    $store->hit('john', 1_000);
    // A hit beyond the one-day (+1h) retention evicts the ancient entry, so a
    // long-lived shared store stays bounded.
    $newest = 1_000 + (90_001 * 1000);
    $store->hit('john', $newest);

    expect($store->hits('john'))->toBe([$newest]);
});

it('keeps hits inside the retention window', function () {
    $store = new InMemoryStore;

    $store->hit('john', 1_000);
    $store->hit('john', 1_000 + 86_400_000);

    expect($store->hits('john'))->toBe([1_000, 1_000 + 86_400_000]);
});

it('keeps hits oldest-first when one arrives out of order', function () {
    $store = new InMemoryStore;

    $store->hit('john', 20);
    $store->hit('john', 10);
    $store->hit('john', 30);

    expect($store->hits('john'))->toBe([10, 20, 30]);
});

/**
 * How many owners and penalties the store holds — its memory, which only the internals show.
 *
 * @return array{0: int, 1: int}
 */
function inMemorySize(InMemoryStore $store): array
{
    return (fn (): array => [count($this->timestamps), count($this->penalties)])->call($store);
}

// Bug: only the owner being hit was pruned and penalties were never dropped, so a long-running
// worker keyed per user ("user-{$id}") grew without bound.
it('sweeps idle owners and long-expired penalties from the whole store', function () {
    $store = new InMemoryStore;
    $tenDays = 10 * 86_400_000;

    foreach (range(1, 1000) as $id) {
        $store->hit("user-{$id}:minute", 0);
        $store->penalizeUntil("user-{$id}", 1_000);
    }

    $store->hit('active:minute', $tenDays - 30_000);
    $store->hit('user-1:minute', $tenDays);

    expect(inMemorySize($store))->toBe([2, 0])
        ->and($store->hits('user-2:minute'))->toBe([])
        ->and($store->penalizedUntil('user-2'))->toBeNull()
        ->and($store->hits('active:minute'))->toBe([$tenDays - 30_000])
        ->and($store->hits('user-1:minute'))->toBe([$tenDays])
        ->and($store->attempt([new Limit('active', 1, 'minute')], $tenDays)->delayMs)->toBe(30_000);
});

it('keeps owners inside retention and penalties not long passed when it sweeps', function () {
    $store = new InMemoryStore;

    $store->hit('recent:day', 0);
    $store->penalizeUntil('recent', 90_000_000);
    $store->hit('other:second', 89_000_000); // sweeps: the day-old hit is still within retention

    expect(inMemorySize($store))->toBe([2, 1])
        ->and($store->hits('recent:day'))->toBe([0])
        ->and($store->penalizedUntil('recent'))->toBe(90_000_000);
});

it('holds no entry for an owner whose hits were all cleared', function () {
    $store = new InMemoryStore;

    $store->clear('ghost:minute', PHP_INT_MAX);
    $store->hit('john:minute', 10);
    $store->clear('john:minute', PHP_INT_MAX);

    expect(inMemorySize($store))->toBe([0, 0])
        ->and($store->hits('john:minute'))->toBe([]);
});
