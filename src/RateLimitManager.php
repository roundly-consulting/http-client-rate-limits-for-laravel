<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits;

use Illuminate\Contracts\Config\Repository;
use RoundlyConsulting\HttpClientRateLimits\DataTransferObjects\LimiterProfileData;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\SleepDeferrer;
use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidDeferrerException;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidStoreException;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\UnknownLimiterProfileException;
use RoundlyConsulting\HttpClientRateLimits\Store\CacheStore;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\HttpClientRateLimits\Store\RedisStore;
use RoundlyConsulting\HttpClientRateLimits\Store\Store;

/**
 * Resolves the configured store/deferrer and builds RateLimit middleware. Bound
 * as a singleton so a facade can resolve it and the host can override defaults.
 *
 * The configured store is resolved once per configuration and shared by every
 * rate limit this manager builds, so hits accumulate across separate calls in
 * the same process. The default InMemoryStore is therefore per-process only —
 * use the CacheStore, RedisStore or DatabaseStore to share limits between workers.
 */
class RateLimitManager
{
    private ?Store $store = null;

    private ?Deferrer $deferrer = null;

    /**
     * Stores resolved from config, keyed by their configuration, shared for the
     * lifetime of this (singleton) manager.
     *
     * @var array<string, Store>
     */
    private array $resolvedStores = [];

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
                store: $this->store(),
                deferrer: $this->deferrer(),
            ),
        );
    }

    /**
     * The store new rate limits record hits in: an explicit override, else the
     * configured store — the same instance for every limit (per process).
     */
    public function store(): Store
    {
        return $this->store ?? $this->resolveStore();
    }

    /**
     * The deferrer new rate limits wait with: an explicit override, else the configured one.
     */
    public function deferrer(): Deferrer
    {
        return $this->deferrer ?? $this->resolveDeferrer();
    }

    /**
     * Build a RateLimit from a named profile defined under the [limiters] config key.
     */
    public function profile(string $name): RateLimit
    {
        $limiters = $this->config->get('http-client-rate-limits.limiters', []);

        if (! is_array($limiters) || ! array_key_exists($name, $limiters) || ! is_array($limiters[$name])) {
            throw UnknownLimiterProfileException::for($name);
        }

        /** @var array<string, mixed> $profileConfig */
        $profileConfig = $limiters[$name];

        return $this->make(LimiterProfileData::fromConfig($profileConfig)->toLimit());
    }

    /**
     * Build a RateLimit that enforces several windows at once (the strictest wins).
     *
     * @param  list<Limit|RateLimit>  $limits
     */
    public function compound(array $limits): RateLimit
    {
        $resolved = array_map(
            static fn (Limit|RateLimit $limit): Limit => $limit instanceof RateLimit
                ? $limit->getLimiter()->getLimit()
                : $limit,
            $limits,
        );

        $primary = $resolved[0] ?? new Limit;

        $rateLimit = $this->make($primary);

        foreach (array_slice($resolved, 1) as $additional) {
            $rateLimit->getLimiter()->addLimit($additional);
        }

        return $rateLimit;
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
            $connection = is_string($connection) ? $connection : 'default';

            return $this->resolvedStores[$store.'|'.$connection] ??= new RedisStore($connection);
        }

        if ($store === CacheStore::class) {
            $cacheStore = $this->config->get('http-client-rate-limits.cache_store');
            $cacheStore = is_string($cacheStore) ? $cacheStore : null;
            $cachePrefix = $this->config->get('http-client-rate-limits.cache_prefix', 'http-client-rate-limits');
            $cachePrefix = is_string($cachePrefix) ? $cachePrefix : 'http-client-rate-limits';

            return $this->resolvedStores[$store.'|'.$cacheStore.'|'.$cachePrefix] ??= new CacheStore(
                store: $cacheStore,
                prefix: $cachePrefix,
            );
        }

        return $this->resolvedStores[$store] ??= new $store;
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
