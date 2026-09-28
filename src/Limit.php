<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits;

use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidLimitException;

final class Limit
{
    protected Timespan $timespan;

    /** Maximum defer (ms) before failing fast, or null for no ceiling. */
    protected ?int $maxWaitMs = null;

    /** Random jitter (ms) added to/subtracted from defers, 0 disables it. */
    protected int $jitterMs = 0;

    /** Whether to self-tune from rate-limit response headers. */
    protected bool $adaptive = false;

    public function __construct(
        protected string $key = 'global',
        protected int $maxAttempts = 60,
        Timespan|string $timespan = Timespan::Second,
        protected bool $trim = false,
    ) {
        $this->maxAttempts = $this->validMaxAttempts($maxAttempts);
        $this->timespan = $this->normalizeTimespan($timespan);
    }

    public function perSecond(int $maxAttempts): self
    {
        return $this->maxAttempts($maxAttempts, Timespan::Second);
    }

    public function perMinute(int $maxAttempts): self
    {
        return $this->maxAttempts($maxAttempts, Timespan::Minute);
    }

    public function perHour(int $maxAttempts): self
    {
        return $this->maxAttempts($maxAttempts, Timespan::Hour);
    }

    public function perDay(int $maxAttempts): self
    {
        return $this->maxAttempts($maxAttempts, Timespan::Day);
    }

    public function maxAttempts(int $maxAttempts, Timespan|string $timespan = Timespan::Second): self
    {
        $this->maxAttempts = $this->validMaxAttempts($maxAttempts);
        $this->timespan = $this->normalizeTimespan($timespan);

        return $this;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function isOverMaxAttempts(int $attempt): bool
    {
        return $attempt > $this->getMaxAttempts();
    }

    public function isUnderMaxAttempts(int $attempt): bool
    {
        return $attempt < $this->getMaxAttempts();
    }

    public function by(string $key): self
    {
        $this->key = $key;

        return $this;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * The key this window's hits are recorded under in the store: the owner key
     * plus the window. Limits on one owner with different windows (5/sec AND
     * 100/min — compound or stacked middleware) then each count a request exactly
     * once, and trimming the shorter window can never erase the longer one's
     * history. Limits sharing both key and window share one budget.
     */
    public function storeKey(): string
    {
        return "{$this->key}:{$this->timespan->value}";
    }

    public function trim(bool $trim = true): self
    {
        $this->trim = $trim;

        return $this;
    }

    public function shouldTrim(): bool
    {
        return $this->trim;
    }

    public function maxWait(int $maxWaitMs): self
    {
        $this->maxWaitMs = $maxWaitMs;

        return $this;
    }

    public function getMaxWait(): ?int
    {
        return $this->maxWaitMs;
    }

    public function hasMaxWait(): bool
    {
        return $this->maxWaitMs !== null;
    }

    public function exceedsMaxWait(int $delayMs): bool
    {
        return $this->maxWaitMs !== null && $delayMs > $this->maxWaitMs;
    }

    public function jitter(int $jitterMs): self
    {
        $this->jitterMs = max(0, $jitterMs);

        return $this;
    }

    public function getJitter(): int
    {
        return $this->jitterMs;
    }

    public function adaptive(bool $adaptive = true): self
    {
        $this->adaptive = $adaptive;

        return $this;
    }

    public function isAdaptive(): bool
    {
        return $this->adaptive;
    }

    public function getTimespan(): string
    {
        return $this->timespan->value;
    }

    public function getTimespanEnum(): Timespan
    {
        return $this->timespan;
    }

    public function timespanLengthInMs(): int
    {
        return $this->timespan->lengthInMs();
    }

    /**
     * A budget below one could never let a request through: reject it instead of passing
     * the first request (the old behaviour) or waiting forever.
     */
    protected function validMaxAttempts(int $maxAttempts): int
    {
        if ($maxAttempts < 1) {
            throw InvalidLimitException::maxAttempts($maxAttempts);
        }

        return $maxAttempts;
    }

    protected function normalizeTimespan(Timespan|string $timespan): Timespan
    {
        return $timespan instanceof Timespan
            ? $timespan
            : Timespan::fromValue($timespan);
    }
}
