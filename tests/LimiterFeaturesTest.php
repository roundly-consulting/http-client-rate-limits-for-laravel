<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use RoundlyConsulting\HttpClientRateLimits\DataTransferObjects\AttemptResult;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\RateLimitExceededException;
use RoundlyConsulting\HttpClientRateLimits\Jitter\Randomizer;
use RoundlyConsulting\HttpClientRateLimits\Jitter\RandomRandomizer;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\Limiter;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\HttpClientRateLimits\Tests\FixedRandomizer;
use RoundlyConsulting\HttpClientRateLimits\Tests\TestDeferrer;
use RoundlyConsulting\HttpClientRateLimits\Tests\TestStore;

it('exposes a getter and setter for the randomizer', function () {
    $limiter = new Limiter(
        limit: new Limit,
        store: new InMemoryStore,
        deferrer: new TestDeferrer,
    );

    expect($limiter->getRandomizer())
        ->toBeInstanceOf(RandomRandomizer::class);

    $fixed = new FixedRandomizer(7);

    expect($limiter->setRandomizer($fixed))->toBe($limiter)
        ->and($limiter->getRandomizer())->toBe($fixed);
});

it('defers by the strictest of several compound windows', function () {
    $store = new InMemoryStore;
    $deferrer = new TestDeferrer(1_000_000);

    // 5/sec AND 1/min: with one hit, the per-minute window is the strictest.
    $limiter = new Limiter(
        limit: new Limit(maxAttempts: 5, timespan: 'second'),
        store: $store,
        deferrer: $deferrer,
    );
    $limiter->addLimit(new Limit(maxAttempts: 1, timespan: 'minute'));

    $store->hit('global:second', 1_000_000);
    $store->hit('global:minute', 1_000_000);

    [$delay, $strictest] = $limiter->strictestDelay(1_000_000);

    expect($delay)->toBe(60_000)
        ->and($strictest->getTimespan())->toBe('minute')
        ->and($limiter->getLimits())->toHaveCount(2);
});

it('records a hit on every compound window when allowed', function () {
    $store = new InMemoryStore;

    $limiter = new Limiter(
        limit: new Limit(key: 'sec', maxAttempts: 5, timespan: 'second'),
        store: $store,
        deferrer: new TestDeferrer(1_000_000),
    );
    $limiter->addLimit(new Limit(key: 'min', maxAttempts: 100, timespan: 'minute'));

    $limiter->handle(fn () => null);

    expect($store->hits('sec:second'))->toBe([1_000_000])
        ->and($store->hits('min:minute'))->toBe([1_000_000]);
});

it('counts each request once when compound windows share an owner key', function () {
    $store = new InMemoryStore;
    $deferrer = new TestDeferrer(1_000_000);

    // 5/sec AND 100/min on the same (default) owner key.
    $limiter = new Limiter(
        limit: new Limit(maxAttempts: 5, timespan: 'second'),
        store: $store,
        deferrer: $deferrer,
    );
    $limiter->addLimit(new Limit(maxAttempts: 100, timespan: 'minute'));

    foreach (range(1, 5) as $ignored) {
        $limiter->handle(fn () => null);
    }

    // All five fit the 5/sec budget — nothing waited.
    expect($deferrer->timestamp())->toBe(1_000_000);

    $limiter->handle(fn () => null);

    // The sixth waits out the one-second window.
    expect($deferrer->timestamp())->toBe(1_001_000);
});

it('counts each request once when two compound limits share a window and key', function () {
    $store = new InMemoryStore;

    $limiter = new Limiter(
        limit: new Limit(key: 'api', maxAttempts: 5, timespan: 'second'),
        store: $store,
        deferrer: new TestDeferrer(1_000_000),
    );
    $limiter->addLimit(new Limit(key: 'api', maxAttempts: 10, timespan: 'second'));

    $limiter->handle(fn () => null);

    expect($store->hits('api:second'))->toBe([1_000_000]);
});

it('keeps a longer window intact when a shorter window on the same key trims', function () {
    $store = new InMemoryStore;
    $deferrer = new TestDeferrer(1_000_000);

    // 5/sec (trimmed) AND 3/min on the same owner key.
    $limiter = new Limiter(
        limit: (new Limit(maxAttempts: 5, timespan: 'second'))->trim(),
        store: $store,
        deferrer: $deferrer,
    );
    $limiter->addLimit(new Limit(maxAttempts: 3, timespan: 'minute'));

    foreach (range(1, 3) as $ignored) {
        $limiter->handle(fn () => null);
        $deferrer->defer(2_000, 'global'); // space the calls out past the 1-second window
    }

    $limiter->handle(fn () => null);

    // The 3/min window still remembers the first hit, so the fourth call waits
    // until that hit leaves the minute (first hit + 60s).
    expect($deferrer->timestamp())->toBe(1_060_000);
});

it('throws when the computed defer exceeds the max wait', function () {
    $store = new InMemoryStore;
    $deferrer = new TestDeferrer(1_000_000);

    $limiter = new Limiter(
        limit: (new Limit(maxAttempts: 1, timespan: 'hour'))->maxWait(5_000),
        store: $store,
        deferrer: $deferrer,
    );

    $store->hit('global:hour', 1_000_000);

    $limiter->handle(fn () => 'never');
})->throws(RateLimitExceededException::class);

it('exposes the offending key and delays on the max wait exception', function () {
    $store = new InMemoryStore;
    $store->hit('acct:hour', 1_000_000);

    $limiter = new Limiter(
        limit: (new Limit(key: 'acct', maxAttempts: 1, timespan: 'hour'))->maxWait(5_000),
        store: $store,
        deferrer: new TestDeferrer(1_000_000),
    );

    try {
        $limiter->handle(fn () => 'never');
        $this->fail('Expected RateLimitExceededException.');
    } catch (RateLimitExceededException $e) {
        expect($e->key)->toBe('acct')
            ->and($e->delayMs)->toBe(3_600_000)
            ->and($e->maxWaitMs)->toBe(5_000);
    }
});

it('does not throw when the defer is within the max wait', function () {
    $store = new InMemoryStore;

    $limiter = new Limiter(
        limit: (new Limit(maxAttempts: 5, timespan: 'second'))->maxWait(5_000),
        store: $store,
        deferrer: new TestDeferrer(1_000_000),
    );

    expect($limiter->handle(fn () => 'ok'))->toBe('ok');
});

it('adds deterministic jitter to the defer', function () {
    $store = new InMemoryStore;
    $store->hit('global:second', 1_000_000);

    $limiter = new Limiter(
        limit: (new Limit(maxAttempts: 1, timespan: 'second'))->jitter(50),
        store: $store,
        deferrer: new TestDeferrer(1_000_000),
        randomizer: new FixedRandomizer(40),
    );

    // Base defer 1000ms + fixed jitter 40ms.
    [$delay] = $limiter->strictestDelay(1_000_000);

    expect($delay)->toBe(1_040);
});

it('never shortens a wait with jitter, whatever the randomizer returns', function () {
    $store = new InMemoryStore;
    $store->hit('global:second', 1_000_000);

    $limiter = new Limiter(
        limit: (new Limit(maxAttempts: 1, timespan: 'second'))->jitter(5_000),
        store: $store,
        deferrer: new TestDeferrer(1_000_000),
        randomizer: new FixedRandomizer(-100_000),
    );

    [$delay] = $limiter->strictestDelay(1_000_000);

    expect($delay)->toBe(1_000);
});

it('enforces the max wait when a compound window is the bottleneck', function () {
    $store = new InMemoryStore;
    $store->hit('acct:hour', 1_000_000);

    // The 5s ceiling is set on the primary (per-second) window, but the per-hour
    // window it runs alongside is what forces the wait.
    $limiter = new Limiter(
        limit: (new Limit(key: 'acct', maxAttempts: 5, timespan: 'second'))->maxWait(5_000),
        store: $store,
        deferrer: $deferrer = new TestDeferrer(1_000_000),
    );
    $limiter->addLimit(new Limit(key: 'acct', maxAttempts: 1, timespan: 'hour'));

    try {
        $limiter->handle(fn () => 'never');
        $this->fail('Expected RateLimitExceededException.');
    } catch (RateLimitExceededException $e) {
        expect($e->key)->toBe('acct')
            ->and($e->delayMs)->toBe(3_600_000)
            ->and($e->maxWaitMs)->toBe(5_000)
            ->and($deferrer->timestamp())->toBe(1_000_000); // never slept
    }
});

it('uses the tightest max wait configured on any compound window', function () {
    $store = new InMemoryStore;
    $store->hit('global:minute', 1_000_000);

    $limiter = new Limiter(
        limit: (new Limit(maxAttempts: 5, timespan: 'second'))->maxWait(30_000),
        store: $store,
        deferrer: new TestDeferrer(1_000_000),
    );
    // The bottleneck's own 90s ceiling would allow the 60s wait; the 30s one must win.
    $limiter->addLimit((new Limit(maxAttempts: 1, timespan: 'minute'))->maxWait(90_000));

    $limiter->handle(fn () => 'never');
})->throws(RateLimitExceededException::class);

it('applies the configured jitter when a compound window is the bottleneck', function () {
    $store = new InMemoryStore;
    $store->hit('global:hour', 1_000_000);

    $limiter = new Limiter(
        limit: (new Limit(maxAttempts: 5, timespan: 'second'))->jitter(50),
        store: $store,
        deferrer: new TestDeferrer(1_000_000),
        randomizer: new FixedRandomizer(30),
    );
    $limiter->addLimit(new Limit(maxAttempts: 1, timespan: 'hour'));

    [$delay, $strictest] = $limiter->strictestDelay(1_000_000);

    expect($delay)->toBe(3_600_000 + 30)
        ->and($strictest->getTimespan())->toBe('hour');
});

it('reports remaining, availableIn and tooManyAttempts for the primary window', function () {
    $store = new InMemoryStore;
    $deferrer = new TestDeferrer(1_000_000);

    $limiter = new Limiter(
        limit: new Limit(key: 'acct', maxAttempts: 3, timespan: 'second'),
        store: $store,
        deferrer: $deferrer,
    );

    expect($limiter->remaining())->toBe(3)
        ->and($limiter->availableIn())->toBe(0)
        ->and($limiter->tooManyAttempts())->toBeFalse();

    $store->hit('acct:second', 1_000_000);
    $store->hit('acct:second', 1_000_000);
    $store->hit('acct:second', 1_000_000);

    expect($limiter->remaining())->toBe(0)
        ->and($limiter->availableIn())->toBe(1_000)
        ->and($limiter->tooManyAttempts())->toBeTrue();
});

it('honours a stored penalty as the delay floor', function () {
    $store = new InMemoryStore;
    $store->penalizeUntil('global', 1_005_000);

    $limiter = new Limiter(
        limit: new Limit(maxAttempts: 100, timespan: 'second'),
        store: $store,
        deferrer: new TestDeferrer(1_000_000),
    );

    expect($limiter->delayUntilNextRequestInMs(1_000_000))->toBe(5_000);
});

it('self-tunes from a Retry-After response header when adaptive', function () {
    $store = new InMemoryStore;

    $limiter = new Limiter(
        limit: (new Limit(key: 'api', maxAttempts: 100, timespan: 'second'))->adaptive(),
        store: $store,
        deferrer: new TestDeferrer(1_000_000),
    );

    $response = new Response(new PsrResponse(429, ['Retry-After' => '7']));

    $limiter->handle(fn () => $response);

    // Penalty recorded 7s after the (post-defer) timestamp.
    expect($store->penalizedUntil('api'))->toBe(1_000_000 + 7_000);
});

it('self-tunes from X-RateLimit headers when remaining is zero (delta reset)', function () {
    $store = new InMemoryStore;

    // now far in the future so a small reset is treated as a relative delta.
    $now = 100_000_000;

    $limiter = new Limiter(
        limit: (new Limit(key: 'api', maxAttempts: 100, timespan: 'second'))->adaptive(),
        store: $store,
        deferrer: new TestDeferrer($now),
    );

    $response = new Response(new PsrResponse(200, [
        'X-RateLimit-Remaining' => '0',
        'X-RateLimit-Reset' => '30',
    ]));

    $limiter->handle(fn () => $response);

    expect($store->penalizedUntil('api'))->toBe($now + 30_000);
});

it('ignores X-RateLimit-Reset when it is not numeric', function () {
    $store = new InMemoryStore;

    $limiter = new Limiter(
        limit: (new Limit(key: 'api', maxAttempts: 100, timespan: 'second'))->adaptive(),
        store: $store,
        deferrer: new TestDeferrer(1_000_000),
    );

    $response = new Response(new PsrResponse(200, [
        'X-RateLimit-Remaining' => '0',
        'X-RateLimit-Reset' => 'soon',
    ]));

    $limiter->handle(fn () => $response);

    expect($store->penalizedUntil('api'))->toBeNull();
});

it('does not self-tune when the limit is not adaptive', function () {
    $store = new InMemoryStore;

    $limiter = new Limiter(
        limit: new Limit(key: 'api', maxAttempts: 100, timespan: 'second'),
        store: $store,
        deferrer: new TestDeferrer(1_000_000),
    );

    $response = new Response(new PsrResponse(429, ['Retry-After' => '7']));

    $limiter->handle(fn () => $response);

    expect($store->penalizedUntil('api'))->toBeNull();
});

it('ignores adaptive headers that ask for no backoff', function () {
    $store = new InMemoryStore;

    $limiter = new Limiter(
        limit: (new Limit(key: 'api', maxAttempts: 100, timespan: 'second'))->adaptive(),
        store: $store,
        deferrer: new TestDeferrer(1_000_000),
    );

    $response = new Response(new PsrResponse(200, ['X-RateLimit-Remaining' => '42']));

    $limiter->handle(fn () => $response);

    expect($store->penalizedUntil('api'))->toBeNull();
});

it('does not penalize when remaining is zero but no reset header is present', function () {
    $store = new InMemoryStore;

    $limiter = new Limiter(
        limit: (new Limit(key: 'api', maxAttempts: 100, timespan: 'second'))->adaptive(),
        store: $store,
        deferrer: new TestDeferrer(1_000_000),
    );

    $response = new Response(new PsrResponse(200, ['X-RateLimit-Remaining' => '0']));

    $limiter->handle(fn () => $response);

    expect($store->penalizedUntil('api'))->toBeNull();
});

it('does not adapt when the callback returns a non-response', function () {
    $store = new InMemoryStore;

    $limiter = new Limiter(
        limit: (new Limit(key: 'api', maxAttempts: 100, timespan: 'second'))->adaptive(),
        store: $store,
        deferrer: new TestDeferrer(1_000_000),
    );

    $limiter->handle(fn () => 'plain string');

    expect($store->penalizedUntil('api'))->toBeNull();
});

it('treats a large reset value as an absolute epoch timestamp', function () {
    $store = new InMemoryStore;

    // now = 1_000_000 ms => 1000 epoch seconds; reset at 1100 epoch seconds = +100s.
    $limiter = new Limiter(
        limit: (new Limit(key: 'api', maxAttempts: 100, timespan: 'second'))->adaptive(),
        store: $store,
        deferrer: new TestDeferrer(1_000_000),
    );

    $response = new Response(new PsrResponse(200, [
        'X-RateLimit-Remaining' => '0',
        'X-RateLimit-Reset' => '1100',
    ]));

    $limiter->handle(fn () => $response);

    expect($store->penalizedUntil('api'))->toBe(1_000_000 + 100_000);
});

it('ignores an X-RateLimit-Reset epoch that has already passed', function () {
    $store = new InMemoryStore;

    // A real-world clock (ms); the server's reset epoch is two seconds behind it
    // — e.g. clock skew, or a response that took a moment to arrive.
    $now = 1_790_000_000_000;

    $limiter = new Limiter(
        limit: (new Limit(key: 'api', maxAttempts: 100, timespan: 'second'))->adaptive(),
        store: $store,
        deferrer: new TestDeferrer($now),
    );

    $response = new Response(new PsrResponse(200, [
        'X-RateLimit-Remaining' => '0',
        'X-RateLimit-Reset' => '1789999998',
    ]));

    $limiter->handle(fn () => $response);

    // A passed reset asks for no wait — never a ~57-year "delta".
    expect($store->penalizedUntil('api'))->toBeNull();
});

// Bug: jitter drew from [-jitter, +jitter], so its minus side sent before the window freed.
it('never sends before the window frees, even with jitter at its minimum', function () {
    $store = new InMemoryStore;
    $deferrer = new TestDeferrer(1_000_000);
    $lowest = new class implements Randomizer
    {
        public function between(int $min, int $max): int
        {
            return $min;
        }
    };

    $limiter = new Limiter((new Limit('j', 1, 'second'))->jitter(50), $store, $deferrer, $lowest);

    $limiter->handle(fn () => null);
    $deferrer->defer(980, 'j');   // 20ms before the slot frees
    $limiter->handle(fn () => null);

    [$first, $second] = $store->hits('j:second');

    expect($second - $first)->toBeGreaterThanOrEqual(1_000);
});

it('lengthens a wait by at most the jitter', function () {
    $store = new InMemoryStore;
    $store->hit('global:second', 1_000_000);
    $highest = new class implements Randomizer
    {
        public function between(int $min, int $max): int
        {
            return $max;
        }
    };

    $limiter = new Limiter((new Limit(maxAttempts: 1, timespan: 'second'))->jitter(50), $store, new TestDeferrer(1_000_000), $highest);

    [$delay] = $limiter->strictestDelay(1_000_000);

    expect($delay)->toBe(1_050);
});

// Bug: the wait was measured from the oldest hit even when the window held more than the max.
it('waits until an over-full window is back within budget before sending', function () {
    $store = new InMemoryStore;
    $deferrer = new TestDeferrer(1_000_040);

    // 4 hits in the last second (e.g. a key shared with a larger limit) against 2/sec.
    foreach ([0, 10, 20, 30] as $offset) {
        $store->hit('o:second', 1_000_000 + $offset);
    }

    (new Limiter(new Limit('o', 2, 'second'), $store, $deferrer))->handle(fn () => null);

    $hits = $store->hits('o:second');
    $sentAt = end($hits);
    $trailing = array_filter($hits, static fn (int $hit): bool => $hit > $sentAt - 1_000);

    expect($sentAt)->toBe(1_001_020)
        ->and(count($trailing))->toBe(2);
});

it('keeps waiting while a store refuses without a delay', function () {
    $deferrer = new TestDeferrer(1_000);
    $store = new class extends TestStore
    {
        public int $attempts = 0;

        public function attempt(array $limits, int $timestamp): AttemptResult
        {
            return ++$this->attempts < 3
                ? AttemptResult::deferred(0, $limits[0], 0)
                : AttemptResult::allowed();
        }
    };

    (new Limiter(new Limit('spin', 1, 'second'), $store, $deferrer))->handle(fn () => null);

    // Each refusal steps on by at least 1ms instead of spinning in place.
    expect($store->attempts)->toBe(3)
        ->and($deferrer->timestamp())->toBe(1_002);
});

it('takes a deferrer that did not move its clock at its word', function () {
    $store = new InMemoryStore;
    $frozen = new class implements Deferrer
    {
        /** @var list<int> */
        public array $defers = [];

        public function timestamp(): int
        {
            return 5_000;
        }

        public function defer(int $ms, string $key): void
        {
            $this->defers[] = $ms;
        }
    };

    $limiter = new Limiter(new Limit('frozen', 1, 'second'), $store, $frozen);
    $limiter->handle(fn () => null);
    $limiter->handle(fn () => null);

    // One simulated wait, then the hit lands where the wait said the slot frees.
    expect($frozen->defers)->toBe([1_000])
        ->and($store->hits('frozen:second'))->toBe([5_000, 6_000]);
});

it('caps a huge X-RateLimit-Reset instead of overflowing', function () {
    $store = new InMemoryStore;

    $limiter = new Limiter(
        limit: (new Limit(key: 'api', maxAttempts: 100, timespan: 'second'))->adaptive(),
        store: $store,
        deferrer: new TestDeferrer(1_000_000),
    );

    $limiter->handle(fn () => new Response(new PsrResponse(200, [
        'X-RateLimit-Remaining' => '0',
        'X-RateLimit-Reset' => '99999999999999999999',
    ])));

    expect($store->penalizedUntil('api'))->toBe(1_000_000 + 86_400_000);
});

// Bug: ctype_digit() refused a fractional reset ("1790000030.250"), so a server asking for a
// 30-second wait got none.
it('honours a fractional X-RateLimit-Reset, rounding it up', function (string $reset, int $penaltyMs) {
    $store = new InMemoryStore;
    $now = 1_790_000_000_000;

    $limiter = new Limiter(
        limit: (new Limit(key: 'api', maxAttempts: 100, timespan: 'second'))->adaptive(),
        store: $store,
        deferrer: new TestDeferrer($now),
    );

    $limiter->handle(fn () => new Response(new PsrResponse(200, [
        'X-RateLimit-Remaining' => '0',
        'X-RateLimit-Reset' => $reset,
    ])));

    expect($store->penalizedUntil('api'))->toBe($now + $penaltyMs);
})->with([
    'epoch' => ['1790000030.250', 31_000],
    'epoch, zero fraction' => ['1790000030.000', 30_000],
    'delta' => ['2.5', 3_000],
    'huge' => ['99999999999999999999.5', 86_400_000],
]);

it('still ignores an X-RateLimit-Reset that is not a non-negative number', function (string $reset) {
    $store = new InMemoryStore;

    $limiter = new Limiter(
        limit: (new Limit(key: 'api', maxAttempts: 100, timespan: 'second'))->adaptive(),
        store: $store,
        deferrer: new TestDeferrer(1_000_000),
    );

    $limiter->handle(fn () => new Response(new PsrResponse(200, [
        'X-RateLimit-Remaining' => '0',
        'X-RateLimit-Reset' => $reset,
    ])));

    expect($store->penalizedUntil('api'))->toBeNull();
})->with(['negative' => ['-30'], 'exponent' => ['1e3'], 'two points' => ['1.2.3']]);
