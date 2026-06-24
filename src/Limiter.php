<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits;

use RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer;
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
            $this->deferrer->defer($delay);
        }

        $this->store->hit($this->limit->getKey(), $this->deferrer->timestamp());

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
}
