<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits;

use ArrayObject;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use RoundlyConsulting\HttpClientRateLimits\DataTransferObjects\LimiterProfileData;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\ReleaseDeferrer;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\SleepDeferrer;
use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidDeferrerException;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidStoreException;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\UnknownLimiterProfileException;
use RoundlyConsulting\HttpClientRateLimits\Store\CacheStore;
use RoundlyConsulting\HttpClientRateLimits\Store\DatabaseStore;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\HttpClientRateLimits\Store\RedisStore;
use RoundlyConsulting\HttpClientRateLimits\Store\Store;
use RoundlyConsulting\HttpClientRateLimits\Support\ConfigValue;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * The package's single entry point (the `RateLimits` facade root): resolves the configured
 * store/deferrer and builds RateLimit middleware. Bound as a singleton so the facade and
 * injected code share it and the host can override defaults.
 *
 * The configured store is resolved once per configuration and shared by every
 * rate limit this manager (or any `using*()` copy of it) builds, so hits accumulate
 * across separate calls in the same process. The default InMemoryStore is therefore
 * per-process only — use the CacheStore, RedisStore or DatabaseStore to share limits
 * between workers.
 */
class RateLimitManager
{
    private ?Store $store = null;

    private ?Deferrer $deferrer = null;

    /**
     * Stores resolved from config, keyed by their configuration, shared for the
     * lifetime of this (singleton) manager — and with every `using*()` / `releasingJob()`
     * copy of it: an object, so a clone keeps pointing at the same map.
     *
     * @var ArrayObject<string, Store>
     */
    private readonly ArrayObject $resolvedStores;

    public function __construct(private readonly Repository $config)
    {
        /** @var ArrayObject<string, Store> $stores */
        $stores = new ArrayObject;

        $this->resolvedStores = $stores;
    }

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

    /**
     * The same manager, deferring by releasing a queued job back onto the queue instead of
     * sleeping the worker. `$job` exposes Laravel's `release(int $seconds)` (e.g. it uses
     * `InteractsWithQueue`); the attempt unwinds with a `JobReleasedException`, which the
     * job's `HandlesRateLimitRelease` middleware turns into a clean end of the attempt.
     */
    public function releasingJob(object $job): self
    {
        return $this->usingDeferrer(new ReleaseDeferrer($job));
    }

    /**
     * Seconds a server's `Retry-After` header asks for (delta-seconds or an HTTP-date), or
     * null when it is absent or unparseable.
     */
    public function retryAfter(Response|RequestException $response): ?int
    {
        return RetryAfter::seconds($response);
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
     * Build a RateLimit from a named profile defined under the [limiters] config key. A profile
     * without `by` is keyed by its name, so each profile keeps its own budget.
     */
    public function profile(string $name): RateLimit
    {
        $limiters = $this->config->get('http-client-rate-limits.limiters', []);

        if (! is_array($limiters) || ! array_key_exists($name, $limiters) || ! is_array($limiters[$name])) {
            throw UnknownLimiterProfileException::for($name);
        }

        /** @var array<string, mixed> $profileConfig */
        $profileConfig = $limiters[$name];

        return $this->make(LimiterProfileData::fromConfig($profileConfig, "http-client-rate-limits.limiters.{$name}", $name)->toLimit());
    }

    /**
     * Build a RateLimit that enforces several windows at once (the strictest wins). Takes
     * copies of every window given (all of a RateLimit's), so re-keying the result with
     * `by()` never reaches the limits passed in.
     *
     * @param  list<Limit|RateLimit>  $limits
     */
    public function compound(array $limits): RateLimit
    {
        $resolved = [];

        foreach ($limits as $limit) {
            foreach ($limit instanceof RateLimit ? $limit->getLimiter()->getLimits() : [$limit] as $window) {
                $resolved[] = clone $window;
            }
        }

        $rateLimit = $this->make($resolved[0] ?? new Limit);

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
        $store = $this->config->get('http-client-rate-limits.store');
        $store = ConfigValue::isSet($store) ? $store : InMemoryStore::class;

        if (! is_string($store) || ! is_a($store, Store::class, true)) {
            throw InvalidStoreException::for($store);
        }

        if ($store === RedisStore::class) {
            $connection = $this->stringSetting('http-client-rate-limits.redis_connection') ?? 'default';

            return $this->resolvedStores[$store.'|'.$connection] ??= new RedisStore($connection);
        }

        if ($store === CacheStore::class) {
            $cacheStore = $this->stringSetting('http-client-rate-limits.cache_store');
            $cachePrefix = $this->stringSetting('http-client-rate-limits.cache_prefix') ?? 'http-client-rate-limits';

            return $this->resolvedStores[$store.'|'.$cacheStore.'|'.$cachePrefix] ??= new CacheStore(
                store: $cacheStore,
                prefix: $cachePrefix,
            );
        }

        if ($store === DatabaseStore::class) {
            $connection = $this->stringSetting('http-client-rate-limits.database_connection');

            return $this->resolvedStores[$store.'|'.$connection] ??= new DatabaseStore($connection);
        }

        return $this->resolvedStores[$store] ??= new $store;
    }

    /**
     * A string store setting: null when not set — absent, null or blank (a host's
     * `KEY=`) — so the caller's documented default applies, else the string. A
     * non-string value throws InvalidConfigurationException instead of quietly
     * falling back to the default connection, store or prefix.
     */
    private function stringSetting(string $key): ?string
    {
        $value = $this->config->get($key);

        return ConfigValue::isSet($value) ? Config::for([$key => $value])->requireString($key) : null;
    }

    private function resolveDeferrer(): Deferrer
    {
        $deferrer = $this->config->get('http-client-rate-limits.deferrer');
        $deferrer = ConfigValue::isSet($deferrer) ? $deferrer : SleepDeferrer::class;

        if (! is_string($deferrer) || ! is_a($deferrer, Deferrer::class, true)) {
            throw InvalidDeferrerException::for($deferrer);
        }

        return new $deferrer;
    }
}
