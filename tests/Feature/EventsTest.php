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
    $store->hit('global', 0);

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
