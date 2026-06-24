<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Store;

use Illuminate\Support\Facades\Redis;

class RedisStore implements Store
{
    public function __construct(protected string $connection = 'default') {}

    public function hit(string $owner, int $timestamp): void
    {
        Redis::connection($this->connection)->zadd($this->key($owner), $timestamp, $timestamp);
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
