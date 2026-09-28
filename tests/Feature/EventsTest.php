<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;
use RoundlyConsulting\HttpClientRateLimits\Events\RequestAllowed;
use RoundlyConsulting\HttpClientRateLimits\Events\RequestDeferred;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\Limiter;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\HttpClientRateLimits\Tests\TestDeferrer;

it('dispatches RequestAllowed when a request passes through', function () {
    Event::fake();

    $limiter = new Limiter(
        limit: new Limit(maxAttempts: 5, timespan: Timespan::Minute),
        store: new InMemoryStore,
        deferrer: new TestDeferrer,
    );

    $limiter->handle(fn () => null);

    Event::assertDispatched(RequestAllowed::class, function (RequestAllowed $event): bool {
        return $event->key === 'global'
            && $event->hitsInWindow === 1
            && $event->timespan === Timespan::Minute;
    });
    Event::assertNotDispatched(RequestDeferred::class);
});

it('dispatches RequestDeferred with the delay payload when throttled', function () {
    Event::fake();

    $store = new InMemoryStore;
    $store->hit('global:minute', 0);

    $limiter = new Limiter(
        limit: new Limit(maxAttempts: 1, timespan: Timespan::Minute),
        store: $store,
        deferrer: new TestDeferrer,
    );

    $limiter->handle(fn () => null);

    Event::assertDispatched(RequestDeferred::class, function (RequestDeferred $event): bool {
        return $event->delayMs === 60_000
            && $event->key === 'global'
            && $event->timespan === Timespan::Minute;
    });
});

it('reports the real hit count of the window that caused the deferral', function () {
    Event::fake();

    // Another process already pushed the shared minute window past its budget of 2.
    $store = new InMemoryStore;
    $store->hit('global:minute', 1_000);
    $store->hit('global:minute', 2_000);
    $store->hit('global:minute', 3_000);

    $limiter = new Limiter(
        limit: new Limit(maxAttempts: 2, timespan: Timespan::Minute),
        store: $store,
        deferrer: new TestDeferrer(10_000),
    );

    $limiter->handle(fn () => null);

    Event::assertDispatched(RequestDeferred::class, fn (RequestDeferred $event): bool => $event->hitsInWindow === 3);
});

it('reports the hit count of the compound window that forced the wait', function () {
    Event::fake();

    // 5/sec is free (nothing in the last second); 2/min holds 3 hits and forces the wait.
    $store = new InMemoryStore;
    $store->hit('api:minute', 1_000);
    $store->hit('api:minute', 2_000);
    $store->hit('api:minute', 3_000);

    $limiter = new Limiter(
        limit: new Limit(key: 'api', maxAttempts: 5, timespan: Timespan::Second),
        store: $store,
        deferrer: new TestDeferrer(10_000),
    );
    $limiter->addLimit(new Limit(key: 'api', maxAttempts: 2, timespan: Timespan::Minute));

    $limiter->handle(fn () => null);

    // 3 hits against a budget of 2: the wait is for hit [3 - 2] (at 2000) to expire, so
    // only one remains — waiting out the oldest alone would leave the window still full.
    Event::assertDispatched(RequestDeferred::class, fn (RequestDeferred $event): bool => $event->hitsInWindow === 3
        && $event->timespan === Timespan::Minute
        && $event->delayMs === 52_000);
});

it('reports zero hits when a server penalty alone forces the wait', function () {
    Event::fake();

    $store = new InMemoryStore;
    $store->penalizeUntil('api', 15_000);

    $limiter = new Limiter(
        limit: new Limit(key: 'api', maxAttempts: 100, timespan: Timespan::Second),
        store: $store,
        deferrer: new TestDeferrer(10_000),
    );

    $limiter->handle(fn () => null);

    Event::assertDispatched(RequestDeferred::class, fn (RequestDeferred $event): bool => $event->hitsInWindow === 0
        && $event->delayMs === 5_000);
});

it('runs without error when no event dispatcher is bound', function () {
    app()->forgetInstance('events');
    app()->offsetUnset('events');

    $limiter = new Limiter(
        limit: new Limit(maxAttempts: 5, timespan: Timespan::Minute),
        store: new InMemoryStore,
        deferrer: new TestDeferrer,
    );

    $executed = false;
    $limiter->handle(function () use (&$executed) {
        $executed = true;
    });

    expect($executed)->toBeTrue();
});

it('suppresses events when the config flag is disabled', function () {
    Event::fake();
    config()->set('http-client-rate-limits.events_enabled', false);

    $limiter = new Limiter(
        limit: new Limit(maxAttempts: 5, timespan: Timespan::Minute),
        store: new InMemoryStore,
        deferrer: new TestDeferrer,
    );

    $limiter->handle(fn () => null);

    Event::assertNotDispatched(RequestAllowed::class);
    Event::assertNotDispatched(RequestDeferred::class);
});
