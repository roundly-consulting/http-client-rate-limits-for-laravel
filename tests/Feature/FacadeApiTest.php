<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\ReleaseDeferrer;
use RoundlyConsulting\HttpClientRateLimits\Events\RateLimitReset;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidDeferrerException;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\JobReleasedException;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;
use RoundlyConsulting\HttpClientRateLimits\RateLimit;
use RoundlyConsulting\HttpClientRateLimits\RateLimitManager;
use RoundlyConsulting\HttpClientRateLimits\Store\DatabaseStore;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\HttpClientRateLimits\Tests\TestDeferrer;

function releasableJob(): object
{
    return new class
    {
        /** @var list<int> */
        public array $released = [];

        public function release(int $delay): void
        {
            $this->released[] = $delay;
        }
    };
}

it('reads Retry-After from a response or a request exception through the facade', function () {
    $response = new Response(new PsrResponse(429, ['Retry-After' => '30']));

    expect(RateLimits::retryAfter($response))->toBe(30)
        ->and(RateLimits::retryAfter(new RequestException($response)))->toBe(30)
        ->and(RateLimits::retryAfter(new Response(new PsrResponse(200))))->toBeNull();
});

it('releases a queued job instead of sleeping', function () {
    $job = releasableJob();
    $manager = RateLimits::releasingJob($job);
    $limit = $manager->perMinute(1)->by('queue-api');

    expect($manager)->toBeInstanceOf(RateLimitManager::class)
        ->and($manager)->not->toBe(RateLimits::getFacadeRoot())
        ->and($limit->getDeferrer())->toBeInstanceOf(ReleaseDeferrer::class)
        ->and(RateLimits::perMinute(1)->getDeferrer())->not->toBeInstanceOf(ReleaseDeferrer::class);

    $limit->handle(fn () => 'first');

    expect(fn () => $manager->perMinute(1)->by('queue-api')->handle(fn () => 'second'))
        ->toThrow(JobReleasedException::class);

    expect($job->released)->toHaveCount(1)
        ->and($job->released[0])->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});

it('refuses a job without a release method', function () {
    RateLimits::releasingJob(new stdClass);
})->throws(InvalidDeferrerException::class);

// Regression: a using*() copy cloned the resolved-store map, so a releasingJob()/usingDeferrer()
// limit on the default in-memory store started from an empty window every time.
it('shares the process store between the manager and its releasingJob copies', function () {
    $first = RateLimits::releasingJob(releasableJob())->perMinute(1)->by('shared');
    $second = RateLimits::releasingJob(releasableJob())->perMinute(1)->by('shared');

    expect($first->getStore())->toBe($second->getStore())
        ->and($first->getStore())->toBe(RateLimits::store());
});

it('resets a key so the next request goes straight through', function () {
    Event::fake([RateLimitReset::class]);
    $store = new InMemoryStore;
    rateLimitsUsing($store, new TestDeferrer(1_000_000));

    $limit = RateLimits::perMinute(2)->by('stripe')->alongside(RateLimits::perSecond(1)->by('stripe'));
    $limit->handle(fn () => null);
    $limit->handle(fn () => null);

    expect($limit->remaining())->toBe(0)
        ->and(RateLimits::perMinute(2)->by('stripe')->reset())->toBeInstanceOf(RateLimit::class)
        ->and($store->hits('stripe:minute'))->toBe([])
        ->and($store->hits('stripe:second'))->not->toBe([])
        ->and($limit->reset()->remaining())->toBe(2)
        ->and($store->hits('stripe:second'))->toBe([]);

    // A server penalty is not lifted by a reset: the window stays closed until it passes.
    $store->penalizeUntil('stripe', 5_000_000);

    expect($limit->reset()->remaining())->toBe(0)
        ->and($store->penalizedUntil('stripe'))->toBe(5_000_000);

    Event::assertDispatched(RateLimitReset::class, fn (RateLimitReset $event): bool => $event->key === 'stripe');
});

it('offers the same API to an injected manager', function () {
    $manager = app(RateLimitManager::class);
    $response = new Response(new PsrResponse(503, ['Retry-After' => '12']));

    expect($manager)->toBe(RateLimits::getFacadeRoot())
        ->and($manager->retryAfter($response))->toBe(12)
        ->and($manager->releasingJob(releasableJob())->deferrer())->toBeInstanceOf(ReleaseDeferrer::class)
        ->and($manager->perHour(3)->by('di')->reset()->remaining())->toBe(3);
});

it('records resets and allowed requests in the fake', function () {
    Http::fake(['*' => Http::response('ok')]);
    $fake = RateLimits::fake();

    $fake->assertNothingReset()->assertNothingAllowed();

    expect(fn () => $fake->assertReset())->toThrow(AssertionFailedError::class, 'Expected a rate limit to be reset')
        ->and(fn () => $fake->assertReset('stripe'))->toThrow(AssertionFailedError::class, 'Expected the rate limit [stripe] to be reset');

    RateLimits::perMinute(60)->by('stripe')->reset();
    app(RateLimitManager::class)->perSecond(1)->by('di')->reset();
    Http::rateLimit(5, by: 'acct')->get('https://api.example.com/one');

    $fake->assertReset()->assertReset('stripe')->assertReset('di');

    expect(fn () => $fake->assertReset('other'))->toThrow(AssertionFailedError::class, '[other]')
        ->and(fn () => $fake->assertNothingReset())->toThrow(AssertionFailedError::class, 'but 2 were')
        ->and(fn () => $fake->assertNothingAllowed())->toThrow(AssertionFailedError::class, 'but 1 were');
});

it('resets a key on the database store', function () {
    rateLimitsUsing(new DatabaseStore, new TestDeferrer(1_000_000));

    $limit = RateLimits::perMinute(1)->by('db-reset');
    $limit->handle(fn () => null);

    expect($limit->remaining())->toBe(0)
        ->and($limit->reset()->remaining())->toBe(1);
});
