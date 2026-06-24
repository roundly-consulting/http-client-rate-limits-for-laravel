<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits;

use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;

class Limit
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
        $this->maxAttempts = $maxAttempts;
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

    protected function normalizeTimespan(Timespan|string $timespan): Timespan
    {
        return $timespan instanceof Timespan
            ? $timespan
            : Timespan::fromValue($timespan);
    }
}
