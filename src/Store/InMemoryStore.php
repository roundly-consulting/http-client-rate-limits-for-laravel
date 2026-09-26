<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Store;

/**
 * Keeps hits in process memory. One instance is shared by every rate limit the
 * app builds (see RateLimitManager), so limits accumulate across calls within a
 * process — but never between processes: use the CacheStore, RedisStore or
 * DatabaseStore when several workers must share a budget.
 */
final class InMemoryStore implements Store
{
    /** One day (the largest supported window) plus a generous margin, in milliseconds. */
    protected const RETENTION_MS = (86_400 + 3_600) * 1_000;

    /** @var array<string, list<int>> */
    protected array $timestamps = [];

    /** @var array<string, int> */
    protected array $penalties = [];

    public function hit(string $owner, int $timestamp): void
    {
        $hits = $this->timestamps[$owner] ?? [];
        $hits[] = $timestamp;

        // Drop entries older than the largest window so a long-lived, shared
        // store stays bounded (hits arrive in order, so check the oldest first).
        $oldest = $timestamp - self::RETENTION_MS;

        if ($hits[0] < $oldest) {
            $hits = array_values(array_filter($hits, static fn (int $record): bool => $record >= $oldest));
        }

        $this->timestamps[$owner] = $hits;
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
        $this->timestamps[$owner] = array_values(
            array_filter($this->hits($owner), fn (int $record) => $record > $timestamp),
        );
    }

    public function penalizeUntil(string $owner, int $timestamp): void
    {
        $this->penalties[$owner] = max($this->penalties[$owner] ?? 0, $timestamp);
    }

    public function penalizedUntil(string $owner): ?int
    {
        return $this->penalties[$owner] ?? null;
    }
}
