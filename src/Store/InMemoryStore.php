<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Store;

use RoundlyConsulting\HttpClientRateLimits\DataTransferObjects\AttemptResult;
use RoundlyConsulting\HttpClientRateLimits\Support\Windows;

/**
 * Keeps hits in process memory. One instance is shared by every rate limit the
 * app builds (see RateLimitManager), so limits accumulate across calls within a
 * process — but never between processes: use the CacheStore, RedisStore or
 * DatabaseStore when several workers must share a budget.
 *
 * `attempt()` is atomic by construction: PHP runs it start to finish in one process,
 * and nothing outside that process can see this store.
 *
 * It stays bounded in a long-running worker: at most once a minute (by hit time) a hit sweeps
 * the whole store of owners whose newest hit, and penalties whose end, lies further back than
 * the retention — entries no window can see any more.
 */
final class InMemoryStore implements Store
{
    /** One day (the largest supported window) plus a generous margin, in milliseconds. */
    protected const RETENTION_MS = (86_400 + 3_600) * 1_000;

    /** How often (by hit time, ms) a hit sweeps the whole store. */
    protected const SWEEP_INTERVAL_MS = 60_000;

    /** The hit time (ms) from which the next hit sweeps the whole store. */
    protected int $nextSweepAt = 0;

    /** @var array<string, list<int>> */
    protected array $timestamps = [];

    /** @var array<string, int> */
    protected array $penalties = [];

    public function attempt(array $limits, int $timestamp): AttemptResult
    {
        $result = Windows::evaluate(
            $limits,
            $timestamp,
            $this->hitsSince(...),
            $this->penalizedUntil(...),
        );

        if (! $result->allowed) {
            return $result;
        }

        foreach (Windows::storeKeys($limits) as $storeKey) {
            $this->hit($storeKey, $timestamp);
        }

        foreach ($limits as $limit) {
            if ($limit->shouldTrim()) {
                $this->clear($limit->storeKey(), $timestamp - $limit->timespanLengthInMs());
            }
        }

        return $result;
    }

    public function hit(string $owner, int $timestamp): void
    {
        $hits = $this->timestamps[$owner] ?? [];
        $hits[] = $timestamp;

        // Keep the list oldest-first even when a hit arrives out of order (another clock).
        $count = count($hits);

        if ($count > 1 && $hits[$count - 2] > $timestamp) {
            sort($hits);
        }

        // Drop entries older than the largest window so a long-lived, shared
        // store stays bounded (the list is ordered, so check the oldest first).
        $oldest = $timestamp - self::RETENTION_MS;

        if ($hits[0] < $oldest) {
            $hits = array_values(array_filter($hits, static fn (int $record): bool => $record >= $oldest));
        }

        $this->timestamps[$owner] = $hits;

        $this->sweep($timestamp);
    }

    /**
     * @return list<int>
     */
    public function hits(string $owner): array
    {
        return $this->timestamps[$owner] ?? [];
    }

    /**
     * @return list<int>
     */
    public function hitsSince(string $owner, int $timestamp): array
    {
        return array_values(
            array_filter($this->hits($owner), fn (int $record) => $record >= $timestamp),
        );
    }

    public function clear(string $owner, int $timestamp): void
    {
        $hits = array_values(
            array_filter($this->hits($owner), fn (int $record) => $record > $timestamp),
        );

        if ($hits === []) {
            unset($this->timestamps[$owner]);

            return;
        }

        $this->timestamps[$owner] = $hits;
    }

    public function penalizeUntil(string $owner, int $timestamp): void
    {
        $this->penalties[$owner] = max($this->penalties[$owner] ?? 0, $timestamp);
    }

    public function penalizedUntil(string $owner): ?int
    {
        return $this->penalties[$owner] ?? null;
    }

    /**
     * Drop every owner whose newest hit, and every penalty whose end, is older than the
     * retention: no window or wait can read them any more, so results never change.
     */
    protected function sweep(int $timestamp): void
    {
        if ($timestamp < $this->nextSweepAt) {
            return;
        }

        $this->nextSweepAt = $timestamp + self::SWEEP_INTERVAL_MS;
        $oldest = $timestamp - self::RETENTION_MS;

        foreach ($this->timestamps as $owner => $hits) {
            // Oldest first, so the last hit is the newest.
            if ($hits === [] || $hits[array_key_last($hits)] < $oldest) {
                unset($this->timestamps[$owner]);
            }
        }

        foreach ($this->penalties as $owner => $until) {
            if ($until < $oldest) {
                unset($this->penalties[$owner]);
            }
        }
    }
}
