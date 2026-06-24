<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Testing;

use Illuminate\Contracts\Config\Repository;
use PHPUnit\Framework\Assert as PHPUnit;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\RecordingDeferrer;
use RoundlyConsulting\HttpClientRateLimits\Events\RequestAllowed;
use RoundlyConsulting\HttpClientRateLimits\Events\RequestDeferred;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\Limiter;
use RoundlyConsulting\HttpClientRateLimits\RateLimit;
use RoundlyConsulting\HttpClientRateLimits\RateLimitManager;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;

/**
 * Drop-in RateLimitManager for tests: never sleeps (records defers instead) and
 * shares a single in-memory store so assertions can verify throttling behaviour
 * without Redis or real waits.
 */
final class RateLimitsFake extends RateLimitManager
{
    private readonly InMemoryStore $sharedStore;

    private readonly RecordingDeferrer $sharedDeferrer;

    /** @var list<RequestDeferred> */
    private array $deferred = [];

    /** @var list<RequestAllowed> */
    private array $allowed = [];

    public function __construct(Repository $config)
    {
        parent::__construct($config);

        $this->sharedStore = new InMemoryStore;
        $this->sharedDeferrer = new RecordingDeferrer;
    }

    public function make(Limit $limit): RateLimit
    {
        return new RateLimit(
            new Limiter(
                limit: $limit,
                store: $this->sharedStore,
                deferrer: $this->sharedDeferrer,
            ),
        );
    }

    public function recordDeferred(RequestDeferred $event): void
    {
        $this->deferred[] = $event;
    }

    public function recordAllowed(RequestAllowed $event): void
    {
        $this->allowed[] = $event;
    }

    public function deferrer(): RecordingDeferrer
    {
        return $this->sharedDeferrer;
    }

    public function store(): InMemoryStore
    {
        return $this->sharedStore;
    }

    public function assertDeferred(?string $key = null): self
    {
        if ($key === null) {
            PHPUnit::assertNotEmpty($this->deferred, 'Expected a request to be deferred, but none were.');

            return $this;
        }

        $keys = array_map(static fn (RequestDeferred $event): string => $event->key, $this->deferred);

        PHPUnit::assertContains(
            $key,
            $keys,
            sprintf('Expected a request for [%s] to be deferred, but it was not.', $key),
        );

        return $this;
    }

    public function assertNothingDeferred(): self
    {
        PHPUnit::assertEmpty(
            $this->deferred,
            sprintf('Expected no requests to be deferred, but %d were.', count($this->deferred)),
        );

        return $this;
    }

    public function assertAllowed(?string $key = null): self
    {
        if ($key === null) {
            PHPUnit::assertNotEmpty($this->allowed, 'Expected a request to be allowed, but none were.');

            return $this;
        }

        $keys = array_map(static fn (RequestAllowed $event): string => $event->key, $this->allowed);

        PHPUnit::assertContains(
            $key,
            $keys,
            sprintf('Expected a request for [%s] to be allowed, but it was not.', $key),
        );

        return $this;
    }

    public function deferredCount(): int
    {
        return count($this->deferred);
    }

    public function allowedCount(): int
    {
        return count($this->allowed);
    }
}
