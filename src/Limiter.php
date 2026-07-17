<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits;

use Illuminate\Http\Client\Response;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer;
use RoundlyConsulting\HttpClientRateLimits\Events\RequestAllowed;
use RoundlyConsulting\HttpClientRateLimits\Events\RequestDeferred;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\RateLimitExceededException;
use RoundlyConsulting\HttpClientRateLimits\Jitter\Randomizer;
use RoundlyConsulting\HttpClientRateLimits\Jitter\RandomRandomizer;
use RoundlyConsulting\HttpClientRateLimits\Store\Store;

final class Limiter
{
    /** @var list<Limit> */
    protected array $additionalLimits = [];

    protected Randomizer $randomizer;

    public function __construct(
        protected Limit $limit,
        protected Store $store,
        protected Deferrer $deferrer,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new RandomRandomizer;
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
        $now = $this->deferrer->timestamp();

        [$delay, $strictest] = $this->strictestDelay($now);

        if ($delay > 0) {
            $this->guardMaxWait($strictest, $delay);

            $this->dispatch(new RequestDeferred(
                key: $strictest->getKey(),
                delayMs: $delay,
                hitsInWindow: $strictest->getMaxAttempts(),
                timespan: $strictest->getTimespanEnum(),
            ));

            $this->deferrer->defer($delay);
        }

        $timestamp = $this->deferrer->timestamp();

        foreach ($this->getLimits() as $limit) {
            $this->store->hit($limit->getKey(), $timestamp);

            if ($limit->shouldTrim()) {
                $this->store->clear(
                    owner: $limit->getKey(),
                    timestamp: $timestamp - $limit->timespanLengthInMs(),
                );
            }
        }

        $this->dispatch(new RequestAllowed(
            key: $this->limit->getKey(),
            hitsInWindow: count($this->store->hitsSince(
                owner: $this->limit->getKey(),
                timestamp: $timestamp - $this->limit->timespanLengthInMs(),
            )),
            timespan: $this->limit->getTimespanEnum(),
        ));

        $result = $callback();

        if ($result instanceof Response && $this->shouldAdapt()) {
            $this->adaptFrom($result, $this->deferrer->timestamp());
        }

        return $result;
    }

    /**
     * Delay (ms) before the *primary* limit allows another request. Preserved
     * for backward compatibility — compound windows are evaluated in handle().
     */
    public function delayUntilNextRequestInMs(int $currentAttemptTimestamp): int
    {
        return $this->delayForLimit($this->limit, $currentAttemptTimestamp);
    }

    /**
     * The largest delay (ms) across every enforced window, plus jitter, with the
     * limit that produced it so callers can attribute the wait.
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
            $maxDelay = $this->applyJitter($strictest, $maxDelay);
        }

        return [$maxDelay, $strictest];
    }

    public function delayForLimit(Limit $limit, int $currentAttemptTimestamp): int
    {
        $timespanLength = $limit->timespanLengthInMs();

        $requestsInTimespan = $this->store->hitsSince(
            owner: $limit->getKey(),
            timestamp: $currentAttemptTimestamp - $timespanLength,
        );

        $windowDelay = 0;

        if (! $limit->isUnderMaxAttempts(count($requestsInTimespan)) && $requestsInTimespan !== []) {
            // Wait out the oldest in-window request before the next is allowed.
            $windowDelay = $timespanLength - ($currentAttemptTimestamp - $requestsInTimespan[0]);
        }

        $penalty = $this->store->penalizedUntil($limit->getKey());

        if ($penalty !== null) {
            $windowDelay = max($windowDelay, $penalty - $currentAttemptTimestamp);
        }

        return max($windowDelay, 0);
    }

    /**
     * Requests still allowed in the primary window right now (never negative).
     */
    public function remaining(): int
    {
        return $this->remainingForLimit($this->limit);
    }

    public function remainingForLimit(Limit $limit): int
    {
        $used = count($this->store->hitsSince(
            owner: $limit->getKey(),
            timestamp: $this->deferrer->timestamp() - $limit->timespanLengthInMs(),
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

    protected function guardMaxWait(Limit $limit, int $delay): void
    {
        if ($limit->exceedsMaxWait($delay)) {
            throw RateLimitExceededException::for(
                key: $limit->getKey(),
                delayMs: $delay,
                maxWaitMs: (int) $limit->getMaxWait(),
            );
        }
    }

    protected function applyJitter(Limit $limit, int $delay): int
    {
        $jitter = $limit->getJitter();

        if ($jitter <= 0) {
            return $delay;
        }

        $spread = $this->randomizer->between(-$jitter, $jitter);

        return max($delay + $spread, 0);
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
     * Read the server's own rate-limit budget from response headers and, when it
     * tells us to back off, record a penalty so the next request waits for it.
     */
    protected function adaptFrom(Response $response, int $now): void
    {
        $seconds = $this->serverBackoffSeconds($response, $now);

        if ($seconds === null || $seconds <= 0) {
            return;
        }

        $until = $now + $seconds * 1000;

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

        if ($reset === '' || ! ctype_digit($reset)) {
            return null;
        }

        // Reset may be epoch seconds (absolute) or a delta; treat large values as epoch.
        $resetValue = (int) $reset;
        $nowSeconds = intdiv($now, 1000);

        $seconds = $resetValue > $nowSeconds ? $resetValue - $nowSeconds : $resetValue;

        return max($seconds, 0);
    }

    protected function dispatch(RequestDeferred|RequestAllowed $event): void
    {
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

        return (bool) config('http-client-rate-limits.events_enabled', true);
    }
}
