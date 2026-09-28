<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits;

use Closure;
use Psr\Http\Message\RequestInterface;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\UndefinedMethodException;
use RoundlyConsulting\HttpClientRateLimits\Store\Store;

/**
 * Guzzle middleware (and a callback wrapper) enforcing one Limiter. Built by the manager —
 * `RateLimits::perMinute(60)`, `Http::rateLimit(...)` — never statically, so there is one
 * entry point and a faked manager sees every limit.
 */
final class RateLimit
{
    public function __construct(protected Limiter $limiter) {}

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

    /**
     * Forget the hits every enforced window recorded for this key, so the next request goes
     * straight through. A server-imposed (adaptive) penalty is not lifted.
     */
    public function reset(): static
    {
        $this->limiter->reset();

        return $this;
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
