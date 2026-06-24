<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\SleepDeferrer;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\Limiter;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\HttpClientRateLimits\Store\Store;
use RoundlyConsulting\HttpClientRateLimits\Tests\TestDeferrer;

it('has getter and seter for limit', function () {
    $limiter = new Limiter(
        limit: new Limit,
        store: new InMemoryStore,
        deferrer: new SleepDeferrer,
    );

    expect($limiter->getLimit())
        ->toBeInstanceOf(Limit::class)
        ->getMaxAttempts()->toBe(60)
        ->getTimespan()->toBe('second');

    $hourlyLimit = (new Limit)->perHour(2);

    $limiter->setLimit($hourlyLimit);

    expect($limiter->getLimit())
        ->toBeInstanceOf(Limit::class)
        ->getMaxAttempts()->toBe(2)
        ->getTimespan()->toBe('hour');
});

it('has getter and seter for store', function () {
    $limiter = new Limiter(
        limit: new Limit,
        store: new InMemoryStore,
        deferrer: new SleepDeferrer,
    );

    expect($limiter->getStore())
        ->toBeInstanceOf(InMemoryStore::class);

    $anonymousStore = new class implements Store
    {
        public function hit(string $owner, int $timestamp): void
        {
            // Just testing
        }

        public function hits(string $owner): array
        {
            return [];
        }

        public function hitsSince(string $owner, int $timestamp): array
        {
            return [];
        }

        public function clear(string $owner, int $timestamp): void
        {
            //
        }

        public function penalizeUntil(string $owner, int $timestamp): void
        {
            //
        }

        public function penalizedUntil(string $owner): ?int
        {
            return null;
        }
    };

    $limiter->setStore($anonymousStore);

    expect($limiter->getStore())
        ->toBe($anonymousStore);
});

it('has getter and seter for deferrer', function () {
    $limiter = new Limiter(
        limit: new Limit,
        store: new InMemoryStore,
        deferrer: new SleepDeferrer,
    );

    expect($limiter->getDeferrer())
        ->toBeInstanceOf(SleepDeferrer::class);

    $testDeferrer = new TestDeferrer;

    $limiter->setDeferrer($testDeferrer);

    expect($limiter->getDeferrer())
        ->toBe($testDeferrer);
});

it('returns delay until next request in ms', function () {
    $limiter = new Limiter(
        limit: new Limit(maxAttempts: 1, timespan: 'minute'),
        store: new InMemoryStore,
        deferrer: new SleepDeferrer,
    );

    Carbon::setTestNow('2023-04-11 14:05:01');

    // No delay since we did not make request yet
    expect($limiter->delayUntilNextRequestInMs(1681221901000))
        ->toBe(0);

    $limiter->getStore()->hit($limiter->getLimit()->getKey(), 1681221901000);

    // Full delay - 1 minute
    expect($limiter->delayUntilNextRequestInMs(1681221901000))
        ->toBe(60000);

    $limiter->getStore()->clear($limiter->getLimit()->getKey(), 1681221901000);
    $limiter->getStore()->hit($limiter->getLimit()->getKey(), 1681221871000);

    // Specific delay - half minute
    expect($limiter->delayUntilNextRequestInMs(1681221901000))
        ->toBe(30000);
});

it('keeps the store bounded to the active window when trimming is enabled', function () {
    $store = new InMemoryStore;

    $limit = (new Limit(maxAttempts: 100, timespan: 'second'))->trim();

    $limiter = new Limiter(limit: $limit, store: $store, deferrer: new TestDeferrer(1_000_000));

    // Pre-seed an old hit far outside the 1-second window.
    $store->hit('global', 0);

    $limiter->handle(fn () => null);

    expect($store->hits('global'))->toBe([1_000_000])
        ->and($limit->shouldTrim())->toBeTrue();
});

it('does not trim when trimming is disabled by default', function () {
    $store = new InMemoryStore;

    $limiter = new Limiter(
        limit: new Limit(maxAttempts: 100, timespan: 'second'),
        store: $store,
        deferrer: new TestDeferrer(1_000_000),
    );

    $store->hit('global', 0);

    $limiter->handle(fn () => null);

    expect($store->hits('global'))->toBe([0, 1_000_000]);
});

it('records hit to store and executes callback', function () {
    $limiter = new Limiter(
        limit: new Limit(maxAttempts: 1, timespan: 'minute'),
        store: new InMemoryStore,
        deferrer: new SleepDeferrer,
    );

    Carbon::setTestNow('2023-04-11 14:05:01');

    $executed = false;

    $limiter->handle(function () use (&$executed) {
        $executed = true;
    });

    expect($executed)
        ->toBeTrue()
        ->and($limiter->getStore()->hits($limiter->getLimit()->getKey()))
        ->toBe([
            1681221901000,
        ]);
});

it('uses deferrer and then records hit to store and executes callback', function () {
    Carbon::setTestNow('2023-04-11 14:05:01');

    $frozenTimeInMs = now()->getTimestampMs();

    $limiter = new Limiter(
        limit: new Limit(maxAttempts: 1, timespan: 'minute'),
        store: new InMemoryStore,
        deferrer: new TestDeferrer($frozenTimeInMs),
    );

    $limiter->getStore()->hit($limiter->getLimit()->getKey(), $frozenTimeInMs);

    $executed = false;

    $limiter->handle(function () use (&$executed) {
        $executed = true;
    });

    $expectedSleepInMs = 60000; // 1 Minute, full delay of limiter

    expect($executed)
        ->toBeTrue()
        ->and($limiter->getDeferrer()->timestamp() - $frozenTimeInMs)
        ->toBe($expectedSleepInMs)
        ->and($limiter->getStore()->hits($limiter->getLimit()->getKey()))
        ->toBe([
            $frozenTimeInMs,
            $frozenTimeInMs + $expectedSleepInMs,
        ]);
});
