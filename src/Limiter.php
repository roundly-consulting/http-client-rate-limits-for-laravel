<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\ResponseInterface;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer;
use RoundlyConsulting\HttpClientRateLimits\Events\RateLimitReset;
use RoundlyConsulting\HttpClientRateLimits\Events\RequestAllowed;
use RoundlyConsulting\HttpClientRateLimits\Events\RequestDeferred;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\RateLimitExceededException;
use RoundlyConsulting\HttpClientRateLimits\Jitter\Randomizer;
use RoundlyConsulting\HttpClientRateLimits\Jitter\RandomRandomizer;
use RoundlyConsulting\HttpClientRateLimits\Store\Store;
use RoundlyConsulting\HttpClientRateLimits\Support\Windows;
use RoundlyConsulting\PackageToolkit\Support\Config;

final class Limiter
{
    /**
     * Reset values at or above this (2001-09-09 as epoch seconds, ~31.7 years as
     * a delta) can only be absolute epoch timestamps.
     */
    protected const EPOCH_THRESHOLD_SECONDS = 1_000_000_000;

    /** @var list<Limit> */
    protected array $additionalLimits = [];

    protected Randomizer $randomizer;

    /** @var (Closure(RequestDeferred|RequestAllowed|RateLimitReset): void)|null */
    protected ?Closure $recorder = null;

    public function __construct(
        protected Limit $limit,
        protected Store $store,
        protected Deferrer $deferrer,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new RandomRandomizer;
    }

    /**
     * A copy owns copies of its limits (the store, deferrer and randomizer stay shared), so
     * re-keying or re-tuning the copy never reaches the original.
     */
    public function __clone()
    {
        $this->limit = clone $this->limit;
        $this->additionalLimits = array_map(
            static fn (Limit $limit): Limit => clone $limit,
            $this->additionalLimits,
        );
    }

    public function getLimit(): Limit
    {
        return $this->limit;
    }

    public function setLimit(Limit $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * Add another window to enforce alongside the primary limit (e.g. 5/sec AND 100/min).
     */
    public function addLimit(Limit $limit): self
    {
        $this->additionalLimits[] = $limit;

        return $this;
    }

    /**
     * Every limit this limiter enforces — the primary plus any compound windows.
     *
     * @return list<Limit>
     */
    public function getLimits(): array
    {
        return [$this->limit, ...$this->additionalLimits];
    }

    public function getRandomizer(): Randomizer
    {
        return $this->randomizer;
    }

    public function setRandomizer(Randomizer $randomizer): self
    {
        $this->randomizer = $randomizer;

        return $this;
    }

    /**
     * Hand every event this limiter raises to `$recorder` too, straight from the limiter —
     * whatever `events_enabled` says and whether or not the dispatcher is faked. Clones share
     * it. How `RateLimits::fake()` records.
     *
     * @internal
     *
     * @param  (Closure(RequestDeferred|RequestAllowed|RateLimitReset): void)|null  $recorder
     */
    public function setRecorder(?Closure $recorder): self
    {
        $this->recorder = $recorder;

        return $this;
    }

    public function getStore(): Store
    {
        return $this->store;
    }

    public function setStore(Store $store): self
    {
        $this->store = $store;

        return $this;
    }

    public function getDeferrer(): Deferrer
    {
        return $this->deferrer;
    }

    public function setDeferrer(Deferrer $deferrer): self
    {
        $this->deferrer = $deferrer;

        return $this;
    }

    public function handle(callable $callback): mixed
    {
        $timestamp = $this->acquire();

        $this->dispatch(new RequestAllowed(
            key: $this->limit->getKey(),
            hitsInWindow: count($this->store->hitsSince(
                owner: $this->limit->storeKey(),
                timestamp: Windows::since($this->limit, $timestamp),
            )),
            timespan: $this->limit->getTimespanEnum(),
        ));

        try {
            $result = $callback();
        } catch (RequestException $exception) {
            // A callback that calls ->throw() hands the 429 back as an exception; the server's
            // backoff is in its response all the same.
            if ($this->shouldAdapt()) {
                $this->adaptFromResult($exception->response);
            }

            throw $exception;
        }

        if (! $this->shouldAdapt()) {
            return $result;
        }

        // As Guzzle middleware the handler hands back a promise of a PSR-7
        // response, so adapt once it settles rather than only on a bare Response.
        if ($result instanceof PromiseInterface) {
            return $result->then(function (mixed $response): mixed {
                $this->adaptFromResult($response);

                return $response;
            });
        }

        $this->adaptFromResult($result);

        return $result;
    }

    /**
     * Take a slot in every enforced window, waiting as long as the store says, and return
     * the timestamp the hit was recorded at. Checking and recording are one atomic store
     * step, and a wait is always followed by a fresh attempt — so workers sharing a store
     * that wake for the same freed slot cannot both take it: the loser waits again.
     */
    protected function acquire(): int
    {
        $now = $this->deferrer->timestamp();
        $waited = 0;

        while (true) {
            $attempt = $this->store->attempt($this->getLimits(), $now);

            if ($attempt->allowed) {
                return $now;
            }

            $window = $attempt->limit ?? $this->limit;

            // A store that refuses without a wait would spin; step on by at least 1ms.
            $delay = $this->applyJitter(max($attempt->delayMs, 1));

            $this->guardMaxWait($window, $waited + $delay);

            $this->dispatch(new RequestDeferred(
                key: $window->getKey(),
                delayMs: $delay,
                // The real count in the window that forced the wait (0 when a
                // server penalty alone did on an empty window), not its budget.
                hitsInWindow: $attempt->hitsInWindow,
                timespan: $window->getTimespanEnum(),
            ));

            $this->deferrer->defer($delay, $window->getKey());

            $waited += $delay;

            // A deferrer whose clock did not move on by the wait (a simulated sleep) is
            // taken at its word; a real one that overslept is read as it is.
            $now = max($this->deferrer->timestamp(), $now + $delay);
        }
    }

    /**
     * Delay (ms) before the *primary* limit allows another request.
     */
    public function delayUntilNextRequestInMs(int $currentAttemptTimestamp): int
    {
        return $this->delayForLimit($this->limit, $currentAttemptTimestamp);
    }

    /**
     * The largest delay (ms) across every enforced window, plus jitter, with the
     * limit that produced it so callers can attribute the wait. A read-only preview:
     * handle() takes the slot atomically through the store.
     *
     * @return array{0: int, 1: Limit}
     */
    public function strictestDelay(int $currentAttemptTimestamp): array
    {
        $strictest = $this->limit;
        $maxDelay = 0;

        foreach ($this->getLimits() as $limit) {
            $delay = $this->delayForLimit($limit, $currentAttemptTimestamp);

            if ($delay > $maxDelay) {
                $maxDelay = $delay;
                $strictest = $limit;
            }
        }

        if ($maxDelay > 0) {
            $maxDelay = $this->applyJitter($maxDelay);
        }

        return [$maxDelay, $strictest];
    }

    public function delayForLimit(Limit $limit, int $currentAttemptTimestamp): int
    {
        $hits = $this->store->hitsSince(
            owner: $limit->storeKey(),
            timestamp: Windows::since($limit, $currentAttemptTimestamp),
        );

        return max(
            Windows::delay($limit, $hits, $currentAttemptTimestamp),
            Windows::penaltyDelay($this->store->penalizedUntil($limit->getKey()), $currentAttemptTimestamp),
        );
    }

    /**
     * Requests still allowed in the primary window right now (never negative; 0 while an
     * adaptive server penalty is in force, since nothing may go until it passes).
     */
    public function remaining(): int
    {
        return $this->remainingForLimit($this->limit);
    }

    public function remainingForLimit(Limit $limit): int
    {
        $now = $this->deferrer->timestamp();

        if (Windows::penaltyDelay($this->store->penalizedUntil($limit->getKey()), $now) > 0) {
            return 0;
        }

        $used = count($this->store->hitsSince(
            owner: $limit->storeKey(),
            timestamp: Windows::since($limit, $now),
        ));

        return max($limit->getMaxAttempts() - $used, 0);
    }

    /**
     * Milliseconds until the primary window allows another request (0 when free).
     */
    public function availableIn(): int
    {
        return $this->delayForLimit($this->limit, $this->deferrer->timestamp());
    }

    /**
     * Whether the primary window is currently exhausted.
     */
    public function tooManyAttempts(): bool
    {
        return $this->availableIn() > 0;
    }

    /**
     * Forget every hit the enforced windows recorded, so the next request is allowed at
     * once. A server-imposed penalty (adaptive mode) stays: the server asked for the wait.
     */
    public function reset(): void
    {
        /** @var array<string, true> $cleared */
        $cleared = [];

        foreach ($this->getLimits() as $limit) {
            $storeKey = $limit->storeKey();

            if (! isset($cleared[$storeKey])) {
                $this->store->clear(owner: $storeKey, timestamp: PHP_INT_MAX);
                $cleared[$storeKey] = true;
            }
        }

        $this->dispatch(new RateLimitReset(key: $this->limit->getKey()));
    }

    /**
     * A max-wait set on any enforced window caps the whole wait — every round of it
     * together, the tightest ceiling winning — so a ceiling on the primary still applies
     * when a window it runs alongside is the bottleneck.
     */
    protected function guardMaxWait(Limit $limit, int $delay): void
    {
        $ceiling = null;

        foreach ($this->getLimits() as $candidate) {
            $maxWait = $candidate->getMaxWait();

            if ($maxWait !== null) {
                $ceiling = $ceiling === null ? $maxWait : min($ceiling, $maxWait);
            }
        }

        if ($ceiling !== null && $delay > $ceiling) {
            throw RateLimitExceededException::for(
                key: $limit->getKey(),
                delayMs: $delay,
                maxWaitMs: $ceiling,
            );
        }
    }

    /**
     * Lengthen the wait by up to the largest jitter configured on any enforced window,
     * whichever window forced the wait. Jitter only ever adds: shortening a wait would send
     * before the window frees.
     */
    protected function applyJitter(int $delay): int
    {
        $jitter = 0;

        foreach ($this->getLimits() as $limit) {
            $jitter = max($jitter, $limit->getJitter());
        }

        if ($jitter <= 0) {
            return $delay;
        }

        return $delay + max($this->randomizer->between(0, $jitter), 0);
    }

    protected function shouldAdapt(): bool
    {
        foreach ($this->getLimits() as $limit) {
            if ($limit->isAdaptive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adapt from whatever the request produced: an Illuminate response (callback
     * path) or a PSR-7 response (middleware path). Anything else is ignored.
     */
    protected function adaptFromResult(mixed $result): void
    {
        if ($result instanceof ResponseInterface) {
            $result = new Response($result);
        }

        if ($result instanceof Response) {
            $this->adaptFrom($result, $this->deferrer->timestamp());
        }
    }

    /**
     * Read the server's own rate-limit budget from response headers and, when it
     * tells us to back off, record a penalty so the next request waits for it.
     */
    protected function adaptFrom(Response $response, int $now): void
    {
        $seconds = $this->serverBackoffSeconds($response, $now);

        if ($seconds === null || $seconds <= 0) {
            return;
        }

        $until = $now + RetryAfter::cap($seconds) * 1000;

        foreach ($this->getLimits() as $limit) {
            if ($limit->isAdaptive()) {
                $this->store->penalizeUntil($limit->getKey(), $until);
            }
        }
    }

    /**
     * Seconds the server asks us to wait, derived from Retry-After, or from
     * X-RateLimit-Remaining hitting zero combined with X-RateLimit-Reset.
     */
    protected function serverBackoffSeconds(Response $response, int $now): ?int
    {
        $retryAfter = RetryAfter::seconds($response);

        if ($retryAfter !== null) {
            return $retryAfter;
        }

        $remaining = $response->header('X-RateLimit-Remaining');

        if ($remaining === '' || (int) $remaining > 0) {
            return null;
        }

        $reset = $response->header('X-RateLimit-Reset');

        // Whole or fractional seconds (`1790000030.250`), never negative.
        if (preg_match('/\A(\d+)(?:\.(\d+))?\z/', $reset, $parts) !== 1) {
            return null;
        }

        // Reset may be epoch seconds (absolute) or a delta. A value ahead of our
        // clock is an epoch still to come; an epoch-sized value at or behind it
        // has already passed (clock skew, a slow response), so it asks for no
        // wait — read as a delta it would stall the limiter for decades.
        $resetValue = self::ceilSeconds($parts[1], $parts[2] ?? '');
        $nowSeconds = intdiv($now, 1000);

        if ($resetValue > $nowSeconds) {
            // The cast saturates at PHP_INT_MAX for an absurd header; cap it in range.
            return RetryAfter::cap($resetValue - $nowSeconds);
        }

        return $resetValue >= self::EPOCH_THRESHOLD_SECONDS ? 0 : $resetValue;
    }

    /**
     * `whole.fraction` seconds rounded up, without a float: the whole part's cast saturates at
     * PHP_INT_MAX for an absurd header, where a float would lose its range.
     */
    protected static function ceilSeconds(string $whole, string $fraction): int
    {
        $seconds = (int) $whole;

        return trim($fraction, '0') !== '' && $seconds < PHP_INT_MAX ? $seconds + 1 : $seconds;
    }

    protected function dispatch(RequestDeferred|RequestAllowed|RateLimitReset $event): void
    {
        if ($this->recorder !== null) {
            ($this->recorder)($event);
        }

        if (! $this->eventsEnabled()) {
            return;
        }

        event($event);
    }

    protected function eventsEnabled(): bool
    {
        // Guard the helper so the limiter still works when constructed outside
        // a container (e.g. plain unit tests with `new Limiter(...)`).
        if (! function_exists('app') || ! app()->bound('events')) {
            return false;
        }

        return Config::boolean('http-client-rate-limits.events_enabled', true);
    }
}
