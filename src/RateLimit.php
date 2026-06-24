<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits;

use Closure;
use Psr\Http\Message\RequestInterface;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\SleepDeferrer;
use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidDeferrerException;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidStoreException;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\UndefinedMethodException;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\HttpClientRateLimits\Store\RedisStore;
use RoundlyConsulting\HttpClientRateLimits\Store\Store;

/**
 * @phpstan-consistent-constructor
 */
class RateLimit
{
    protected static ?Store $defaultStore = null;

    protected static ?Deferrer $defaultDeferrer = null;

    public function __construct(protected Limiter $limiter) {}

    public static function use(?Store $defaultStore = null, ?Deferrer $defaultDeferrer = null): void
    {
        static::$defaultStore = $defaultStore;
        static::$defaultDeferrer = $defaultDeferrer;
    }

    public static function make(Limit $limit): static
    {
        // Prefer the container-bound manager (config-driven, overridable), but
        // keep a static fallback so `RateLimit::*` still works without a container.
        if (static::$defaultStore === null
            && static::$defaultDeferrer === null
            && function_exists('app')
            && app()->bound(RateLimitManager::class)) {
            /** @var RateLimit $rateLimit */
            $rateLimit = app(RateLimitManager::class)->make($limit);

            return new static($rateLimit->getLimiter());
        }

        return new static(
            limiter: new Limiter(
                limit: $limit,
                store: static::$defaultStore ?: static::defaultStore(),
                deferrer: static::$defaultDeferrer ?: static::defaultDeferrer(),
            )
        );
    }

    protected static function defaultStore(): Store
    {
        $store = config('http-client-rate-limits.store', InMemoryStore::class);

        if (! is_string($store) || ! is_a($store, Store::class, true)) {
            throw InvalidStoreException::for($store);
        }

        if ($store === RedisStore::class) {
            $connection = config('http-client-rate-limits.redis_connection', 'default');

            return new RedisStore(is_string($connection) ? $connection : 'default');
        }

        return new $store;
    }

    protected static function defaultDeferrer(): Deferrer
    {
        $deferrer = config('http-client-rate-limits.deferrer', SleepDeferrer::class);

        if (! is_string($deferrer) || ! is_a($deferrer, Deferrer::class, true)) {
            throw InvalidDeferrerException::for($deferrer);
        }

        return new $deferrer;
    }

    public static function perSecond(int $maxAttempts = 1): static
    {
        return static::make(
            limit: new Limit(maxAttempts: $maxAttempts, timespan: Timespan::Second),
        );
    }

    public static function perMinute(int $maxAttempts = 1): static
    {
        return static::make(
            limit: new Limit(maxAttempts: $maxAttempts, timespan: Timespan::Minute),
        );
    }

    public static function perHour(int $maxAttempts = 1): static
    {
        return static::make(
            limit: new Limit(maxAttempts: $maxAttempts, timespan: Timespan::Hour),
        );
    }

    public static function perDay(int $maxAttempts = 1): static
    {
        return static::make(
            limit: new Limit(maxAttempts: $maxAttempts, timespan: Timespan::Day),
        );
    }

    public function getLimiter(): Limiter
    {
        return $this->limiter;
    }

    /**
     * Enforce an additional window alongside the primary limit. Accepts a Limit,
     * a RateLimit (its underlying limit is taken), or a list of either.
     *
     * @param  Limit|RateLimit|list<Limit|RateLimit>  $limit
     */
    public function alongside(Limit|RateLimit|array $limit): static
    {
        foreach (is_array($limit) ? $limit : [$limit] as $entry) {
            $this->limiter->addLimit(
                $entry instanceof RateLimit ? $entry->getLimiter()->getLimit() : $entry,
            );
        }

        return $this;
    }

    public function maxWait(int $maxWaitMs): static
    {
        $this->limiter->getLimit()->maxWait($maxWaitMs);

        return $this;
    }

    public function jitter(int $jitterMs): static
    {
        $this->limiter->getLimit()->jitter($jitterMs);

        return $this;
    }

    public function adaptive(bool $adaptive = true): static
    {
        $this->limiter->getLimit()->adaptive($adaptive);

        return $this;
    }

    public function remaining(): int
    {
        return $this->limiter->remaining();
    }

    public function availableIn(): int
    {
        return $this->limiter->availableIn();
    }

    public function tooManyAttempts(): bool
    {
        return $this->limiter->tooManyAttempts();
    }

    public function by(string $key): static
    {
        $this->limiter->getLimit()->by($key);

        return $this;
    }

    public function getKey(): string
    {
        return $this->limiter->getLimit()->getKey();
    }

    public function getMaxAttempts(): int
    {
        return $this->limiter->getLimit()->getMaxAttempts();
    }

    public function isOverMaxAttempts(int $attempt): bool
    {
        return $this->limiter->getLimit()->isOverMaxAttempts($attempt);
    }

    public function isUnderMaxAttempts(int $attempt): bool
    {
        return $this->limiter->getLimit()->isUnderMaxAttempts($attempt);
    }

    public function getTimespan(): string
    {
        return $this->limiter->getLimit()->getTimespan();
    }

    public function getStore(): Store
    {
        return $this->limiter->getStore();
    }

    public function setStore(Store $store): static
    {
        $this->limiter->setStore($store);

        return $this;
    }

    public function getDeferrer(): Deferrer
    {
        return $this->limiter->getDeferrer();
    }

    public function setDeferrer(Deferrer $deferrer): static
    {
        $this->limiter->setDeferrer($deferrer);

        return $this;
    }

    public function delayUntilNextRequestInMs(int $at): int
    {
        return $this->limiter->delayUntilNextRequestInMs($at);
    }

    public function handle(callable $callback): mixed
    {
        return $this->limiter->handle($callback);
    }

    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            return $this->limiter->handle(fn () => $handler($request, $options));
        };
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (method_exists($this->limiter->getLimit(), $name)) {
            return $this->limiter->getLimit()->$name(...$arguments);
        }

        if (method_exists($this->limiter, $name)) {
            return $this->limiter->$name(...$arguments);
        }

        throw UndefinedMethodException::for($name);
    }
}
