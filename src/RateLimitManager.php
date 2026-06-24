<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits;

use Illuminate\Contracts\Config\Repository;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\SleepDeferrer;
use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidDeferrerException;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidStoreException;
use RoundlyConsulting\HttpClientRateLimits\Store\CacheStore;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\HttpClientRateLimits\Store\RedisStore;
use RoundlyConsulting\HttpClientRateLimits\Store\Store;

/**
 * Resolves the configured store/deferrer and builds RateLimit middleware. Bound
 * as a singleton so a facade can resolve it and the host can override defaults.
 */
final class RateLimitManager
{
    private ?Store $store = null;

    private ?Deferrer $deferrer = null;

    public function __construct(private readonly Repository $config) {}

    public function usingStore(Store $store): self
    {
        // Clone so overriding defaults doesn't mutate the shared singleton.
        $clone = clone $this;
        $clone->store = $store;

        return $clone;
    }

    public function usingDeferrer(Deferrer $deferrer): self
    {
        $clone = clone $this;
        $clone->deferrer = $deferrer;

        return $clone;
    }

    public function make(Limit $limit): RateLimit
    {
        return new RateLimit(
            new Limiter(
                limit: $limit,
                store: $this->store ?? $this->resolveStore(),
                deferrer: $this->deferrer ?? $this->resolveDeferrer(),
            ),
        );
    }

    public function perSecond(int $maxAttempts = 1): RateLimit
    {
        return $this->make(new Limit(maxAttempts: $maxAttempts, timespan: Timespan::Second));
    }

    public function perMinute(int $maxAttempts = 1): RateLimit
    {
        return $this->make(new Limit(maxAttempts: $maxAttempts, timespan: Timespan::Minute));
    }

    public function perHour(int $maxAttempts = 1): RateLimit
    {
        return $this->make(new Limit(maxAttempts: $maxAttempts, timespan: Timespan::Hour));
    }

    public function perDay(int $maxAttempts = 1): RateLimit
    {
        return $this->make(new Limit(maxAttempts: $maxAttempts, timespan: Timespan::Day));
    }

    private function resolveStore(): Store
    {
        $store = $this->config->get('http-client-rate-limits.store', InMemoryStore::class);

        if (! is_string($store) || ! is_a($store, Store::class, true)) {
            throw InvalidStoreException::for($store);
        }

        if ($store === RedisStore::class) {
            $connection = $this->config->get('http-client-rate-limits.redis_connection', 'default');

            return new RedisStore(is_string($connection) ? $connection : 'default');
        }

        if ($store === CacheStore::class) {
            $cacheStore = $this->config->get('http-client-rate-limits.cache_store');
            $cachePrefix = $this->config->get('http-client-rate-limits.cache_prefix', 'http-client-rate-limits');

            return new CacheStore(
                store: is_string($cacheStore) ? $cacheStore : null,
                prefix: is_string($cachePrefix) ? $cachePrefix : 'http-client-rate-limits',
            );
        }

        return new $store;
    }

    private function resolveDeferrer(): Deferrer
    {
        $deferrer = $this->config->get('http-client-rate-limits.deferrer', SleepDeferrer::class);

        if (! is_string($deferrer) || ! is_a($deferrer, Deferrer::class, true)) {
            throw InvalidDeferrerException::for($deferrer);
        }

        return new $deferrer;
    }
}
