<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidLimitException;
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

// Bug: `by:` re-keyed only the primary window; the per-minute budget stayed shared on `global`.
it('scopes every window of a compound limit to the by owner', function () {
    $store = new InMemoryStore;
    rateLimitsUsing($store, new TestDeferrer(1_000_000));

    Http::rateLimit([RateLimits::perSecond(5), RateLimits::perMinute(100)], by: 'acct-1')->get('https://api.example.com/one');
    Http::rateLimit([RateLimits::perSecond(5), RateLimits::perMinute(100)], by: 'acct-2')->get('https://api.example.com/two');

    expect($store->hits('acct-1:second'))->toHaveCount(1)
        ->and($store->hits('acct-1:minute'))->toHaveCount(1)
        ->and($store->hits('acct-2:second'))->toHaveCount(1)
        ->and($store->hits('acct-2:minute'))->toHaveCount(1)
        ->and($store->hits('global:minute'))->toBe([]);
});

it('re-keys a copy, never the RateLimit or Limit the caller passed', function () {
    $store = new InMemoryStore;
    rateLimitsUsing($store, new TestDeferrer(1_000_000));

    $shared = RateLimits::perSecond(5)->alongside(RateLimits::perMinute(100));
    $limit = new Limit(maxAttempts: 5, timespan: 'hour');

    Http::rateLimit($shared, by: 'acct-1')->get('https://api.example.com/one');
    Http::rateLimit($limit, by: 'acct-1')->get('https://api.example.com/two');

    expect(array_map(static fn (Limit $window): string => $window->getKey(), $shared->getLimiter()->getLimits()))
        ->toBe(['global', 'global'])
        ->and($limit->getKey())->toBe('global')
        ->and($store->hits('acct-1:second'))->toHaveCount(1)
        ->and($store->hits('acct-1:minute'))->toHaveCount(1)
        ->and($store->hits('acct-1:hour'))->toHaveCount(1)
        ->and($store->hits('global:second'))->toBe([]);
});

// Bug: the array form kept only Limit/RateLimit entries, so a profile name or an integer was
// dropped and the request ran under a default 60/second limit on the global key.
it('resolves profile names and per-minute integers inside an array', function () {
    config()->set('http-client-rate-limits.limiters', [
        'github' => ['rate' => 5, 'per' => 'second', 'by' => 'gh'],
    ]);

    $store = RateLimits::fake()->store();

    Http::rateLimit(['github', 30])->get('https://api.example.com/one');

    expect($store->hits('gh:second'))->toHaveCount(1)
        ->and($store->hits('global:minute'))->toHaveCount(1)
        ->and($store->hits('global:second'))->toBe([]);
});

it('reads an integer inside an array as that many per minute', function () {
    $fake = RateLimits::fake();

    foreach (range(1, 31) as $ignored) {
        Http::rateLimit([30])->get('https://api.example.com/things');
    }

    expect($fake->deferrer()->deferCount())->toBe(1)
        ->and($fake->store()->hits('global:minute'))->toHaveCount(31)
        ->and($fake->store()->hits('global:second'))->toBe([]);
});

it('refuses an array entry it cannot turn into a limit', function (mixed $entry) {
    Http::rateLimit([RateLimits::perSecond(5), $entry]);
})->with([
    'float' => [1.5],
    'null' => [null],
    'object' => [new stdClass],
    'nested array' => [[5]],
])->throws(InvalidLimitException::class, 'Http::rateLimit() takes a Limit, a RateLimit, a profile name or a per-minute integer');

it('refuses an empty array instead of falling back to a default limit', function () {
    Http::rateLimit([]);
})->throws(InvalidLimitException::class, 'Http::rateLimit() needs at least one limit');
