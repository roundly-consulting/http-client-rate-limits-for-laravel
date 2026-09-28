<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Deferrer;

/**
 * A deferrer that records every defer instead of sleeping, used by the testing
 * fake so suites can assert on throttling without real waits or Redis. Advances
 * its own clock by the deferred amount so windows still progress deterministically.
 */
final class RecordingDeferrer implements Deferrer
{
    /** @var list<int> */
    private array $defers = [];

    public function __construct(private int $now = 0)
    {
        if ($this->now === 0) {
            $this->now = now()->getTimestampMs();
        }
    }

    public function timestamp(): int
    {
        return $this->now;
    }

    public function defer(int $ms, string $key): void
    {
        $this->defers[] = $ms;
        $this->now += $ms;
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
