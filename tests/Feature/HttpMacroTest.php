<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\HttpClientRateLimits\Tests\TestDeferrer;

beforeEach(function () {
    Http::fake(['*' => Http::response('ok')]);
});

it('attaches a rate limit from a RateLimit instance', function () {
    $deferrer = new TestDeferrer;
    rateLimitsUsing(new InMemoryStore, $deferrer);

    Http::rateLimit(RateLimits::perMinute(1))->get('https://api.example.com/one');
    Http::rateLimit(RateLimits::perMinute(1))->get('https://api.example.com/two');

    // Two calls against a per-minute budget of 1 forces the deferrer to wait.
    expect($deferrer->timestamp())->toBeGreaterThan(0);
});

it('attaches a rate limit from a Limit value object', function () {
    $deferrer = new TestDeferrer;
    rateLimitsUsing(new InMemoryStore, $deferrer);

    Http::rateLimit(new Limit(maxAttempts: 1, timespan: 'minute'))->get('https://api.example.com/one');
    Http::rateLimit(new Limit(maxAttempts: 1, timespan: 'minute'))->get('https://api.example.com/two');

    expect($deferrer->timestamp())->toBeGreaterThan(0);
});

it('treats an integer shorthand as a per-minute limit', function () {
    $deferrer = new TestDeferrer;
    rateLimitsUsing(new InMemoryStore, $deferrer);

    Http::rateLimit(1)->get('https://api.example.com/one');
    Http::rateLimit(1)->get('https://api.example.com/two');

    // Per-minute window means the second call waits a full minute.
    expect($deferrer->timestamp())->toBe(60_000);
});

it('scopes the limit to an owner via the by argument', function () {
    $store = new InMemoryStore;
    rateLimitsUsing($store, new TestDeferrer);

    Http::rateLimit(5, by: 'acct-1')->get('https://api.example.com/one');

    expect($store->hits('acct-1:minute'))->toHaveCount(1)
        ->and($store->hits('global:minute'))->toBeEmpty();
});

it('returns a response through the macro', function () {
    $response = Http::rateLimit(60)->get('https://api.example.com/things');

    expect($response->body())->toBe('ok');
});

it('resolves a named profile from a string', function () {
    config()->set('http-client-rate-limits.limiters', [
        'github' => ['rate' => 1, 'per' => 'minute', 'by' => 'gh'],
    ]);

    $store = RateLimits::fake()->store();

    Http::rateLimit('github')->get('https://api.example.com/one');

    expect($store->hits('gh:minute'))->toHaveCount(1);
});

it('enforces compound windows from an array', function () {
    $fake = RateLimits::fake();

    Http::rateLimit([RateLimits::perSecond(5), RateLimits::perMinute(1)])
        ->get('https://api.example.com/one');
    Http::rateLimit([RateLimits::perSecond(5), RateLimits::perMinute(1)])
        ->get('https://api.example.com/two');

    // The per-minute window of 1 forces the second call to be deferred.
    $fake->assertDeferred();

    expect($fake->deferrer()->deferCount())->toBe(1);
});

it('counts each request once when stacked macro limits share an owner key', function () {
    $deferrer = new TestDeferrer(1_000_000);
    rateLimitsUsing(new InMemoryStore, $deferrer);

    foreach (range(1, 5) as $ignored) {
        Http::rateLimit(RateLimits::perSecond(5))
            ->rateLimit(RateLimits::perMinute(100))
            ->get('https://api.example.com/things');
    }

    // Five requests against 5/sec AND 100/min: neither budget is exhausted.
    expect($deferrer->timestamp())->toBe(1_000_000);

    Http::rateLimit(RateLimits::perSecond(5))
        ->rateLimit(RateLimits::perMinute(100))
        ->get('https://api.example.com/things');

    expect($deferrer->timestamp())->toBe(1_001_000);
});
