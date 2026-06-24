<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits;

use RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer;
use RoundlyConsulting\HttpClientRateLimits\Events\RequestAllowed;
use RoundlyConsulting\HttpClientRateLimits\Events\RequestDeferred;
use RoundlyConsulting\HttpClientRateLimits\Store\Store;

class Limiter
{
    public function __construct(protected Limit $limit, protected Store $store, protected Deferrer $deferrer) {}

    public function getLimit(): Limit
    {
        return $this->limit;
    }

    public function setLimit(Limit $limit): self
    {
        $this->limit = $limit;

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
        if ($delay = $this->delayUntilNextRequestInMs($this->deferrer->timestamp())) {
            $this->dispatch(new RequestDeferred(
                key: $this->limit->getKey(),
                delayMs: $delay,
                hitsInWindow: $this->limit->getMaxAttempts(),
                timespan: $this->limit->getTimespanEnum(),
            ));

            $this->deferrer->defer($delay);
        }

        $timestamp = $this->deferrer->timestamp();

        $this->store->hit($this->limit->getKey(), $timestamp);

        if ($this->limit->shouldTrim()) {
            $this->store->clear(
                owner: $this->limit->getKey(),
                timestamp: $timestamp - $this->limit->timespanLengthInMs(),
            );
        }

        $this->dispatch(new RequestAllowed(
            key: $this->limit->getKey(),
            hitsInWindow: count($this->store->hitsSince(
                owner: $this->limit->getKey(),
                timestamp: $timestamp - $this->limit->timespanLengthInMs(),
            )),
            timespan: $this->limit->getTimespanEnum(),
        ));

        return $callback();
    }

    public function delayUntilNextRequestInMs(int $currentAttemptTimestamp): int
    {
        $timespanLength = $this->limit->timespanLengthInMs();

        $requestsInTimespan = $this->store->hitsSince(
            owner: $this->limit->getKey(),
            timestamp: $currentAttemptTimestamp - $timespanLength,
        );

        if ($this->limit->isUnderMaxAttempts(count($requestsInTimespan))) {
            return 0;
        }

        // Subtract difference between current attempt timestamp and oldest request for current timespan from
        // timespan length in ms.
        return $timespanLength - ($currentAttemptTimestamp - $requestsInTimespan[0]);
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
