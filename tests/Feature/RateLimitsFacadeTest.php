<?php

declare(strict_types=1);

use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\RateLimit;
use RoundlyConsulting\HttpClientRateLimits\Tests\TestDeferrer;
use RoundlyConsulting\HttpClientRateLimits\Tests\TestStore;

it('builds rate limits through the facade', function () {
    expect(RateLimits::perSecond(5))->toBeInstanceOf(RateLimit::class)
        ->and(RateLimits::perMinute(5)->getTimespan())->toBe('minute')
        ->and(RateLimits::perHour(5)->getTimespan())->toBe('hour')
        ->and(RateLimits::perDay(5)->getTimespan())->toBe('day')
        ->and(RateLimits::make(new Limit(maxAttempts: 5, timespan: 'hour'))->getTimespan())->toBe('hour');
});

it('applies fluent overrides through the facade', function () {
    $rateLimit = RateLimits::usingStore($store = new TestStore)
        ->usingDeferrer($deferrer = new TestDeferrer)
        ->perMinute(5);

    expect($rateLimit->getStore())->toBe($store)
        ->and($rateLimit->getDeferrer())->toBe($deferrer);
});
