<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Store;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use RoundlyConsulting\HttpClientRateLimits\DataTransferObjects\AttemptResult;
use RoundlyConsulting\HttpClientRateLimits\Support\Windows;

/**
 * Shares rate-limit state across processes using whatever cache store the host
 * app already runs (file, database, memcached, array, …) — no Redis required.
 *
 * Every read-modify-write — and `attempt()`'s whole check-then-record — runs under the
 * cache's atomic lock, one lock per window key taken in sorted order, when the underlying
 * store is a LockProvider (every built-in Laravel cache store is). Without one it is
 * best-effort: concurrent writers can then both take the last slot.
 */
final class CacheStore implements Store
{
    /** One day (the largest supported window) plus a generous margin, in seconds. */
    protected const RETENTION_SECONDS = 86_400 + 3_600;

    /**
     * @param  int  $lockSeconds  how long a window lock is held at most, and how long a
     *                            writer waits for it before throwing LockTimeoutException
     */
    public function __construct(
        protected ?string $store = null,
        protected string $prefix = 'http-client-rate-limits',
        protected int $ttlSeconds = self::RETENTION_SECONDS,
        protected int $lockSeconds = 5,
    ) {}

    public function attempt(array $limits, int $timestamp): AttemptResult
    {
        return $this->withLocks(
            array_map($this->key(...), Windows::storeKeys($limits)),
            function () use ($limits, $timestamp): AttemptResult {
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
                    $this->record($storeKey, $timestamp);
                }

                foreach ($limits as $limit) {
                    if ($limit->shouldTrim()) {
                        $this->forget($limit->storeKey(), $timestamp - $limit->timespanLengthInMs());
                    }
                }

                return $result;
            },
        );
    }

    public function hit(string $owner, int $timestamp): void
    {
        $this->withLocks([$this->key($owner)], fn () => $this->record($owner, $timestamp));
    }

    /**
     * @return list<int>
     */
    public function hits(string $owner): array
    {
        $hits = $this->cache()->get($this->key($owner), []);

        if (! is_array($hits)) {
            return [];
        }

        $list = array_values(array_map(intval(...), $hits));
        sort($list);

        return $list;
    }

    /**
     * @return list<int>
     */
    public function hitsSince(string $owner, int $timestamp): array
    {
        return array_values(
            array_filter($this->hits($owner), static fn (int $record): bool => $record >= $timestamp),
        );
    }

    public function clear(string $owner, int $timestamp): void
    {
        $this->withLocks([$this->key($owner)], fn () => $this->forget($owner, $timestamp));
    }

    public function penalizeUntil(string $owner, int $timestamp): void
    {
        $this->withLocks([$this->penaltyKey($owner)], function () use ($owner, $timestamp): void {
            $this->cache()->put(
                $this->penaltyKey($owner),
                max($this->penalizedUntil($owner) ?? 0, $timestamp),
                $this->ttlSeconds,
            );
        });
    }

    public function penalizedUntil(string $owner): ?int
    {
        $value = $this->cache()->get($this->penaltyKey($owner));

        return is_numeric($value) ? (int) $value : null;
    }

    public function key(string $owner): string
    {
        return "{$this->prefix}:{$owner}";
    }

    public function penaltyKey(string $owner): string
    {
        return "{$this->prefix}:{$owner}:penalty";
    }

    /**
     * Append a hit and drop entries older than the retention window, so the cached list
     * stays bounded. The caller holds the key's lock.
     */
    protected function record(string $owner, int $timestamp): void
    {
        $oldest = $timestamp - self::RETENTION_SECONDS * 1000;

        $hits = array_values(array_filter(
            [...$this->hits($owner), $timestamp],
            static fn (int $record): bool => $record >= $oldest,
        ));

        $this->cache()->put($this->key($owner), $hits, $this->ttlSeconds);
    }

    /**
     * Drop the hits at or before `$timestamp`. The caller holds the key's lock.
     */
    protected function forget(string $owner, int $timestamp): void
    {
        $kept = array_values(
            array_filter($this->hits($owner), static fn (int $record): bool => $record > $timestamp),
        );

        if ($kept === []) {
            $this->cache()->forget($this->key($owner));

            return;
        }

        $this->cache()->put($this->key($owner), $kept, $this->ttlSeconds);
    }

    /**
     * Run `$callback` holding a lock on every key, taken one by one in the given (sorted)
     * order so two writers locking overlapping sets can never deadlock.
     *
     * @template TReturn
     *
     * @param  list<string>  $keys
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function withLocks(array $keys, Closure $callback): mixed
    {
        $store = $this->cache()->getStore();

        if (! $store instanceof LockProvider || $keys === []) {
            return $callback();
        }

        $key = array_shift($keys);

        return $store->lock($key.':lock', $this->lockSeconds)->block(
            $this->lockSeconds,
            fn () => $this->withLocks($keys, $callback),
        );
    }

    protected function cache(): Repository
    {
        return Cache::store($this->store);
    }
}
