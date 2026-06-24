<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits;

class Limit
{
    public function __construct(
        protected string $key = 'global',
        protected int $maxAttempts = 60,
        protected string $timespan = 'second',
    ) {}

    public function perSecond(int $maxAttempts): self
    {
        return $this->maxAttempts($maxAttempts);
    }

    public function perMinute(int $maxAttempts): self
    {
        return $this->maxAttempts($maxAttempts, 'minute');
    }

    public function perHour(int $maxAttempts): self
    {
        return $this->maxAttempts($maxAttempts, 'hour');
    }

    public function maxAttempts(int $maxAttempts, string $timespan = 'second'): self
    {
        $this->maxAttempts = $maxAttempts;
        $this->timespan = $timespan;

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

    public function getTimespan(): string
    {
        return $this->timespan;
    }

    public function timespanLengthInMs(): int
    {
        return match ($this->timespan) {
            'hour' => 60 * 60 * 1000,
            'minute' => 60 * 1000,
            default => 1000,
        };
    }
}
