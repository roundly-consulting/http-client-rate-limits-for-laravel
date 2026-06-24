<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits;

use Closure;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\SleepDeferrer;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\HttpClientRateLimits\Store\RedisStore;
use RoundlyConsulting\HttpClientRateLimits\Store\Store;
use RuntimeException;

/**
 * @method Limiter setLimit(Limit $limit)
 * @method Limiter setStore(Store $store)
 * @method Limiter setDeferrer(Deferrer $deferrer)
 * @method Limit getLimit()
 * @method Store getStore()
 * @method Deferrer getDeferrer()
 * @method int getMaxAttempts()
 * @method bool isOverMaxAttempts(int $attempt)
 * @method bool isUnderMaxAttempts(int $attempt)
 * @method Limit by(string $key)
 * @method string getKey()
 * @method string getTimespan()
 * @method int timespanLengthInMs()
 * @method mixed handle(callable $callback)
 * @method int delayUntilNextRequestInMs(int $at)
 *
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
            throw new InvalidArgumentException(
                'Configured [http-client-rate-limits.store] must be a class implementing '.Store::class.'.',
            );
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
            throw new InvalidArgumentException(
                'Configured [http-client-rate-limits.deferrer] must be a class implementing '.Deferrer::class.'.',
            );
        }

        return new $deferrer;
    }

    public static function perSecond(int $maxAttempts = 1): static
    {
        return static::make(
            limit: new Limit(maxAttempts: $maxAttempts),
        );
    }

    public static function perMinute(int $maxAttempts = 1): static
    {
        return static::make(
            limit: new Limit(maxAttempts: $maxAttempts, timespan: 'minute'),
        );
    }

    public static function perHour(int $maxAttempts = 1): static
    {
        return static::make(
            limit: new Limit(maxAttempts: $maxAttempts, timespan: 'hour'),
        );
    }

    public function getLimiter(): Limiter
    {
        return $this->limiter;
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

        throw new RuntimeException("Method [{$name}] not found on RateLimit or Limiter class.");
    }
}
