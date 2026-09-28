<?php

declare(strict_types=1);

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;
use RoundlyConsulting\HttpClientRateLimits\RateLimit;
use RoundlyConsulting\HttpClientRateLimits\RateLimitManager;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\HttpClientRateLimits\Tests\TestDeferrer;

/*
 * `->adaptive()` must behave the same whether the limiter wraps a callback
 * (`handle(fn () => Http::get(...))`) or sits in the Guzzle stack as middleware
 * (`Http::rateLimit()` / `Http::withMiddleware()`). As middleware the handler
 * hands back a promise of a PSR-7 response, never an Illuminate Response.
 */

it('self-tunes from Retry-After when attached through the rateLimit macro', function () {
    $store = new InMemoryStore;
    $deferrer = new TestDeferrer(1_000_000);
    rateLimitsUsing($store, $deferrer);

    Http::fake(['*' => Http::sequence()
        ->push('slow down', 429, ['Retry-After' => '7'])
        ->push('ok', 200),
    ]);

    $response = Http::rateLimit(RateLimits::perSecond(100)->by('api')->adaptive())
        ->get('https://api.example.com/one');

    expect($response->status())->toBe(429)
        ->and($store->penalizedUntil('api'))->toBe(1_000_000 + 7_000);

    Http::rateLimit(RateLimits::perSecond(100)->by('api')->adaptive())
        ->get('https://api.example.com/two');

    // The second request waited out the server's 7s backoff before going out.
    expect($deferrer->timestamp())->toBe(1_007_000);
});

it('self-tunes from X-RateLimit headers when attached with withMiddleware', function () {
    $store = new InMemoryStore;
    $now = 100_000_000;
    rateLimitsUsing($store, new TestDeferrer($now));

    Http::fake(['*' => Http::response('ok', 200, [
        'X-RateLimit-Remaining' => '0',
        'X-RateLimit-Reset' => '30',
    ])]);

    Http::withMiddleware(RateLimits::perSecond(100)->by('api')->adaptive())
        ->get('https://api.example.com/one');

    expect($store->penalizedUntil('api'))->toBe($now + 30_000);
});

it('self-tunes from an adaptive named profile used through the macro', function () {
    config()->set('http-client-rate-limits.limiters', [
        'github' => ['rate' => 100, 'per' => 'second', 'by' => 'gh', 'adaptive' => true],
    ]);

    $store = new InMemoryStore;
    $manager = app(RateLimitManager::class)
        ->usingStore($store)
        ->usingDeferrer(new TestDeferrer(1_000_000));
    app()->instance(RateLimitManager::class, $manager);

    Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '5'])]);

    Http::rateLimit('github')->get('https://api.github.com/user');

    expect($store->penalizedUntil('gh'))->toBe(1_000_000 + 5_000);
});

it('self-tunes from each response of a concurrent pool', function () {
    $store = new InMemoryStore;
    rateLimitsUsing($store, new TestDeferrer(1_000_000));

    Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '9'])]);

    $responses = Http::pool(fn (Pool $pool) => [
        $pool->rateLimit(RateLimits::perSecond(100)->by('api')->adaptive())->get('https://api.example.com/a'),
    ]);

    expect($responses[0]->status())->toBe(429)
        ->and($store->penalizedUntil('api'))->toBe(1_000_000 + 9_000);
});

it('leaves the store untouched through the macro when the limit is not adaptive', function () {
    $store = new InMemoryStore;
    rateLimitsUsing($store, new TestDeferrer(1_000_000));

    Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '7'])]);

    Http::rateLimit(RateLimits::perSecond(100)->by('api'))->get('https://api.example.com/one');

    expect($store->penalizedUntil('api'))->toBeNull();
});

// Bug: a Retry-After past PHP_INT_MAX / 1000 overflowed to a float and penalizeUntil(int) threw.
it('caps a huge Retry-After at one day instead of crashing the request', function () {
    $store = new InMemoryStore;
    rateLimitsUsing($store, new TestDeferrer(1_000_000));

    Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '99999999999999999999'])]);

    $response = Http::rateLimit(RateLimits::perSecond(5)->by('ra')->adaptive())->get('https://api.example.com/one');

    expect($response->status())->toBe(429)
        ->and($store->penalizedUntil('ra'))->toBe(1_000_000 + 86_400_000)
        ->and(RateLimits::retryAfter($response))->toBe(86_400);
});

// Bug: remaining() counted only the window's hits, so it said 19 while the penalty held every request.
it('reports nothing remaining while a server penalty is in force', function () {
    $store = new InMemoryStore;
    rateLimitsUsing($store, new TestDeferrer(1_000_000));

    Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '7'])]);

    $limit = RateLimits::perSecond(20)->by('pen')->adaptive();
    Http::rateLimit($limit)->get('https://api.example.com/one');

    expect($limit->availableIn())->toBe(7_000)
        ->and($limit->tooManyAttempts())->toBeTrue()
        ->and($limit->remaining())->toBe(0);
});
