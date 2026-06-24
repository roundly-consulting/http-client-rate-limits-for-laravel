<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;
use RoundlyConsulting\HttpClientRateLimits\RateLimitManager;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\HttpClientRateLimits\Testing\RateLimitsFake;

beforeEach(function () {
    Http::fake(['*' => Http::response('ok')]);
});

it('swaps the manager for a recording fake', function () {
    $fake = RateLimits::fake();

    expect($fake)->toBeInstanceOf(RateLimitsFake::class)
        ->and(app(RateLimitManager::class))->toBe($fake)
        ->and($fake->store())->toBeInstanceOf(InMemoryStore::class);
});

it('records allowed requests without sleeping', function () {
    $fake = RateLimits::fake();

    Http::rateLimit(5, by: 'acct-1')->get('https://api.example.com/one');

    $fake->assertAllowed()
        ->assertAllowed('acct-1')
        ->assertNothingDeferred();

    expect($fake->allowedCount())->toBe(1)
        ->and($fake->deferrer()->deferCount())->toBe(0);
});

it('records deferred requests when a limit is exceeded', function () {
    $fake = RateLimits::fake();

    Http::rateLimit(1, by: 'acct-2')->get('https://api.example.com/one');
    Http::rateLimit(1, by: 'acct-2')->get('https://api.example.com/two');

    $fake->assertDeferred()
        ->assertDeferred('acct-2');

    expect($fake->deferredCount())->toBe(1)
        ->and($fake->deferrer()->deferCount())->toBe(1);
});

it('fails assertDeferred when nothing was deferred', function () {
    $fake = RateLimits::fake();

    Http::rateLimit(5, by: 'acct-3')->get('https://api.example.com/one');

    $fake->assertDeferred();
})->throws(AssertionFailedError::class);

it('fails assertNothingDeferred when something was deferred', function () {
    $fake = RateLimits::fake();

    Http::rateLimit(1, by: 'acct-4')->get('https://api.example.com/one');
    Http::rateLimit(1, by: 'acct-4')->get('https://api.example.com/two');

    $fake->assertNothingDeferred();
})->throws(AssertionFailedError::class);

it('fails assertDeferred for a key that was not deferred', function () {
    $fake = RateLimits::fake();

    Http::rateLimit(1, by: 'acct-5')->get('https://api.example.com/one');
    Http::rateLimit(1, by: 'acct-5')->get('https://api.example.com/two');

    $fake->assertDeferred('other-key');
})->throws(AssertionFailedError::class);

it('fails assertAllowed for a key that was not allowed', function () {
    $fake = RateLimits::fake();

    Http::rateLimit(5, by: 'acct-6')->get('https://api.example.com/one');

    $fake->assertAllowed('nobody');
})->throws(AssertionFailedError::class);

it('fails assertAllowed when nothing was allowed', function () {
    $fake = RateLimits::fake();

    $fake->assertAllowed();
})->throws(AssertionFailedError::class);
