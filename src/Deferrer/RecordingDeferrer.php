<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Deferrer;

/**
 * A deferrer that records every defer instead of sleeping, used by the testing
 * fake so suites can assert on throttling without real waits or Redis. Advances
 * its own clock by the deferred amount so windows still progress deterministically.
 *
 * Built without a start time it follows `now()` — Carbon test time, so `travel()` moves it
 * as it moves the real SleepDeferrer — and never runs behind the time its defers simulated.
 * An explicit start keeps it on its own clock alone.
 */
final class RecordingDeferrer implements Deferrer
{
    /** @var list<int> */
    private array $defers = [];

    private int $now;

    private readonly bool $followsNow;

    public function __construct(int $now = 0)
    {
        $this->followsNow = $now === 0;
        $this->now = $this->followsNow ? now()->getTimestampMs() : $now;
    }

    public function timestamp(): int
    {
        return $this->followsNow ? max(now()->getTimestampMs(), $this->now) : $this->now;
    }

    public function defer(int $ms, string $key): void
    {
        $this->defers[] = $ms;
        $this->now = $this->timestamp() + $ms;
    }

    /**
     * @return list<int>
     */
    public function defers(): array
    {
        return $this->defers;
    }

    public function deferCount(): int
    {
        return count($this->defers);
    }
}
