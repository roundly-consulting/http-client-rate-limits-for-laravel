<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Store;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Shares rate-limit state across processes using whatever cache store the host
 * app already runs (file, database, memcached, array, …) — no Redis required.
 *
 * Read-modify-write over a non-atomic cache can lose concurrent updates, so the
 * write is wrapped in an atomic lock when the underlying store supports one.
 * Use the RedisStore when strict atomicity across many writers is required.
 */
class CacheStore implements Store
{
    /** One day (the largest supported window) plus a generous margin, in seconds. */
    protected const RETENTION_SECONDS = 86_400 + 3_600;

    public function __construct(
        protected ?string $store = null,
        protected string $prefix = 'http-client-rate-limits',
        protected int $ttlSeconds = self::RETENTION_SECONDS,
    ) {}

    public function hit(string $owner, int $timestamp): void
    {
        $this->withLock($owner, function () use ($owner, $timestamp): void {
            $hits = $this->hits($owner);
            $hits[] = $timestamp;

            $this->put($owner, $this->trim($hits, $timestamp));
        });
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

        /** @var list<int> */
        return array_values(array_map(intval(...), $hits));
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
        $this->withLock($owner, function () use ($owner, $timestamp): void {
            $kept = array_values(
                array_filter($this->hits($owner), static fn (int $record): bool => $record > $timestamp),
            );

            if ($kept === []) {
                $this->cache()->forget($this->key($owner));

                return;
            }

            $this->put($owner, $kept);
        });
    }

    public function key(string $owner): string
    {
        return "{$this->prefix}:{$owner}";
    }

    /**
     * Drop entries older than the retention window so the cached list stays bounded.
     *
     * @param  list<int>  $hits
     * @return list<int>
     */
    protected function trim(array $hits, int $timestamp): array
    {
        $oldest = $timestamp - self::RETENTION_SECONDS * 1000;

        return array_values(
            array_filter($hits, static fn (int $record): bool => $record >= $oldest),
        );
    }

    /**
     * @param  list<int>  $hits
     */
    protected function put(string $owner, array $hits): void
    {
        $this->cache()->put($this->key($owner), $hits, $this->ttlSeconds);
    }

    protected function withLock(string $owner, callable $callback): void
    {
        $store = $this->cache()->getStore();

        if ($store instanceof LockProvider) {
            $store->lock($this->key($owner).':lock', 5)->block(5, $callback);

            return;
        }

        $callback();
    }

    protected function cache(): Repository
    {
        return Cache::store($this->store);
    }
}
