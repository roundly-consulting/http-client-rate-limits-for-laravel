<?php

declare(strict_types=1);

use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\RateLimit;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\HttpClientRateLimits\Tests\TestDeferrer;

afterEach(fn () => RateLimit::use());

it('adds a compound limit from a Limit instance', function () {
    $rateLimit = RateLimit::perSecond(5)->alongside(new Limit(maxAttempts: 100, timespan: 'minute'));

    expect($rateLimit)->toBeInstanceOf(RateLimit::class)
        ->and($rateLimit->getLimiter()->getLimits())->toHaveCount(2);
});

it('adds compound limits from a list of RateLimit instances', function () {
    $rateLimit = RateLimit::perSecond(5)->alongside([
        RateLimit::perMinute(100),
        RateLimit::perHour(1_000),
    ]);

    expect($rateLimit->getLimiter()->getLimits())->toHaveCount(3);
});

it('sets a max wait through the fluent surface', function () {
    $rateLimit = RateLimit::perHour(10)->maxWait(5_000);

    expect($rateLimit->getLimiter()->getLimit()->getMaxWait())->toBe(5_000);
});

it('sets jitter through the fluent surface', function () {
    $rateLimit = RateLimit::perMinute(30)->jitter(50);

    expect($rateLimit->getLimiter()->getLimit()->getJitter())->toBe(50);
});

it('opts into adaptive limiting through the fluent surface', function () {
    $rateLimit = RateLimit::perMinute(30)->adaptive();

    expect($rateLimit->getLimiter()->getLimit()->isAdaptive())->toBeTrue();
});

it('exposes preflight inspection helpers', function () {
    RateLimit::use(new InMemoryStore, new TestDeferrer(1_000_000));

    $rateLimit = RateLimit::perMinute(30)->by('acct-1');

    expect($rateLimit->remaining())->toBe(30)
        ->and($rateLimit->availableIn())->toBe(0)
        ->and($rateLimit->tooManyAttempts())->toBeFalse();
});

it('reports exhaustion through preflight helpers after hits', function () {
    $store = new InMemoryStore;
    RateLimit::use($store, new TestDeferrer(1_000_000));

    $rateLimit = RateLimit::perSecond(1)->by('acct-1');

    $store->hit('acct-1', 1_000_000);

    expect($rateLimit->remaining())->toBe(0)
        ->and($rateLimit->tooManyAttempts())->toBeTrue()
        ->and($rateLimit->availableIn())->toBe(1_000);
});
