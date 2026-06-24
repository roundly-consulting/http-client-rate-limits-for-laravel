<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\RateLimit;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\HttpClientRateLimits\Tests\TestDeferrer;

beforeEach(function () {
    Http::fake(['*' => Http::response('ok')]);
});

afterEach(function () {
    RateLimit::use();
});

it('attaches a rate limit from a RateLimit instance', function () {
    $deferrer = new TestDeferrer;
    RateLimit::use(new InMemoryStore, $deferrer);

    Http::rateLimit(RateLimit::perMinute(1))->get('https://api.example.com/one');
    Http::rateLimit(RateLimit::perMinute(1))->get('https://api.example.com/two');

    // Two calls against a per-minute budget of 1 forces the deferrer to wait.
    expect($deferrer->timestamp())->toBeGreaterThan(0);
});

it('attaches a rate limit from a Limit value object', function () {
    $deferrer = new TestDeferrer;
    RateLimit::use(new InMemoryStore, $deferrer);

    Http::rateLimit(new Limit(maxAttempts: 1, timespan: 'minute'))->get('https://api.example.com/one');
    Http::rateLimit(new Limit(maxAttempts: 1, timespan: 'minute'))->get('https://api.example.com/two');

    expect($deferrer->timestamp())->toBeGreaterThan(0);
});

it('treats an integer shorthand as a per-minute limit', function () {
    $deferrer = new TestDeferrer;
    RateLimit::use(new InMemoryStore, $deferrer);

    Http::rateLimit(1)->get('https://api.example.com/one');
    Http::rateLimit(1)->get('https://api.example.com/two');

    // Per-minute window means the second call waits a full minute.
    expect($deferrer->timestamp())->toBe(60_000);
});

it('scopes the limit to an owner via the by argument', function () {
    $store = new InMemoryStore;
    RateLimit::use($store, new TestDeferrer);

    Http::rateLimit(5, by: 'acct-1')->get('https://api.example.com/one');

    expect($store->hits('acct-1'))->toHaveCount(1)
        ->and($store->hits('global'))->toBeEmpty();
});

it('returns a response through the macro', function () {
    $response = Http::rateLimit(60)->get('https://api.example.com/things');

    expect($response->body())->toBe('ok');
});
