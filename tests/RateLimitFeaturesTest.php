<?php

declare(strict_types=1);

use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\RateLimit;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\HttpClientRateLimits\Tests\TestDeferrer;

it('adds a compound limit from a Limit instance', function () {
    $rateLimit = RateLimits::perSecond(5)->alongside(new Limit(maxAttempts: 100, timespan: 'minute'));

    expect($rateLimit)->toBeInstanceOf(RateLimit::class)
        ->and($rateLimit->getLimiter()->getLimits())->toHaveCount(2);
});

it('adds compound limits from a list of RateLimit instances', function () {
    $rateLimit = RateLimits::perSecond(5)->alongside([
        RateLimits::perMinute(100),
        RateLimits::perHour(1_000),
    ]);

    expect($rateLimit->getLimiter()->getLimits())->toHaveCount(3);
});

it('sets a max wait through the fluent surface', function () {
    $rateLimit = RateLimits::perHour(10)->maxWait(5_000);

    expect($rateLimit->getLimiter()->getLimit()->getMaxWait())->toBe(5_000);
});

it('sets jitter through the fluent surface', function () {
    $rateLimit = RateLimits::perMinute(30)->jitter(50);

    expect($rateLimit->getLimiter()->getLimit()->getJitter())->toBe(50);
});

it('opts into adaptive limiting through the fluent surface', function () {
    $rateLimit = RateLimits::perMinute(30)->adaptive();

    expect($rateLimit->getLimiter()->getLimit()->isAdaptive())->toBeTrue();
});

it('exposes preflight inspection helpers', function () {
    rateLimitsUsing(new InMemoryStore, new TestDeferrer(1_000_000));

    $rateLimit = RateLimits::perMinute(30)->by('acct-1');

    expect($rateLimit->remaining())->toBe(30)
        ->and($rateLimit->availableIn())->toBe(0)
        ->and($rateLimit->tooManyAttempts())->toBeFalse();
});

it('reports exhaustion through preflight helpers after hits', function () {
    $store = new InMemoryStore;
    rateLimitsUsing($store, new TestDeferrer(1_000_000));

    $rateLimit = RateLimits::perSecond(1)->by('acct-1');

    $store->hit('acct-1:second', 1_000_000);

    expect($rateLimit->remaining())->toBe(0)
        ->and($rateLimit->tooManyAttempts())->toBeTrue()
        ->and($rateLimit->availableIn())->toBe(1_000);
});
