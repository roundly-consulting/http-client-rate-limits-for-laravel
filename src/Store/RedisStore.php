<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Store;

use Illuminate\Support\Facades\Redis;

class RedisStore implements Store
{
    /** One day (the largest supported window) plus a generous margin, in seconds. */
    protected const RETENTION_SECONDS = 86_400 + 3_600;

    public function __construct(protected string $connection = 'default') {}

    public function hit(string $owner, int $timestamp): void
    {
        $connection = Redis::connection($this->connection);

        $connection->zadd($this->key($owner), $timestamp, $timestamp);

        // Drop entries older than the largest window we support so the sorted
        // set stays bounded, and refresh a key TTL so abandoned owners expire.
        $connection->zremrangebyscore(
            $this->key($owner),
            '0',
            (string) ($timestamp - self::RETENTION_SECONDS * 1000),
        );

        $connection->expire($this->key($owner), self::RETENTION_SECONDS);
    }

    /**
     * @return list<int>
     */
    public function hits(string $owner): array
    {
        return $this->hitsSince($owner, 0);
    }

    /**
     * @return list<int>
     */
    public function hitsSince(string $owner, int $timestamp): array
    {
        /** @var list<int|string> $records */
        $records = Redis::connection($this->connection)
            ->zrangebyscore($this->key($owner), (string) $timestamp, '+INF');

        return array_map(
            static fn (int|string $record): int => (int) $record,
            $records,
        );
    }

    public function clear(string $owner, int $timestamp): void
    {
        Redis::connection($this->connection)
            ->zremrangebyscore($this->key($owner), '0', (string) $timestamp);
    }

    public function key(string $owner): string
    {
        return "http-client-rate-limits:$owner";
    }
}
