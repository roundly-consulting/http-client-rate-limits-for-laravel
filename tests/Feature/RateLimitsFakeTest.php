<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\JobReleasedException;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;
use RoundlyConsulting\HttpClientRateLimits\RateLimitManager;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\HttpClientRateLimits\Testing\RateLimitsFake;
use RoundlyConsulting\HttpClientRateLimits\Tests\TestDeferrer;

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

// Bug: the fake's make() ignored every per-call override, so these never took effect under fake().
it('honours usingStore() under the fake', function () {
    $fake = RateLimits::fake();
    $store = new InMemoryStore;

    $limit = RateLimits::usingStore($store)->perSecond(5)->by('own-store');
    Http::rateLimit($limit)->get('https://api.example.com/one');

    expect($limit->getStore())->toBe($store)
        ->and($limit->getDeferrer())->toBe($fake->deferrer())
        ->and($store->hits('own-store:second'))->toHaveCount(1)
        ->and($fake->store()->hits('own-store:second'))->toBe([]);

    $fake->assertAllowed('own-store');
});

it('honours usingDeferrer() under the fake', function () {
    $fake = RateLimits::fake();
    $deferrer = new TestDeferrer(1_000_000);

    $limit = RateLimits::usingDeferrer($deferrer)->perMinute(1)->by('own-deferrer');
    Http::rateLimit($limit)->get('https://api.example.com/one');
    Http::rateLimit($limit)->get('https://api.example.com/two');

    expect($limit->getDeferrer())->toBe($deferrer)
        ->and($limit->getStore())->toBe($fake->store())
        ->and($deferrer->timestamp())->toBe(1_060_000)
        ->and($fake->deferrer()->deferCount())->toBe(0);

    $fake->assertDeferred('own-deferrer');
});

it('really releases the job for releasingJob() under the fake', function () {
    $fake = RateLimits::fake();
    $job = new class
    {
        /** @var list<int> */
        public array $released = [];

        public function release(int $delay): void
        {
            $this->released[] = $delay;
        }
    };

    Http::rateLimit(RateLimits::perHour(1)->by('fake-job'))->get('https://api.example.com/one');

    try {
        Http::rateLimit(RateLimits::releasingJob($job)->perHour(1)->by('fake-job'))->get('https://api.example.com/two');
        $this->fail('Expected the job to be released.');
    } catch (JobReleasedException $exception) {
        expect($exception->key)->toBe('fake-job');
    }

    expect($job->released)->toHaveCount(1)
        ->and($job->released[0])->toBeGreaterThan(3_500)->toBeLessThanOrEqual(3_600);

    $fake->assertDeferred('fake-job');
});
