<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Store;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RoundlyConsulting\HttpClientRateLimits\DataTransferObjects\AttemptResult;
use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;
use RoundlyConsulting\HttpClientRateLimits\Support\Windows;

/**
 * Keeps hits in Redis sorted sets (score = timestamp, member = timestamp plus a random
 * suffix, so hits in the same millisecond never collapse into one) — shared across
 * processes and servers.
 *
 * `attempt()` is ONE Lua script: Redis runs it without interleaving any other command, so
 * the check across every window and the write are atomic for every client of that Redis.
 * Keys carry the limit key as a hash tag (`http-client-rate-limits:{acct-1}:second`), so a
 * limit's windows and penalty share a Redis Cluster slot; a compound limit mixing several
 * owner keys spans slots and needs a single-node Redis.
 */
final class RedisStore implements Store
{
    /** One day (the largest supported window) plus a generous margin, in seconds. */
    protected const RETENTION_SECONDS = 86_400 + 3_600;

    /**
     * KEYS: the distinct window sets, then the distinct penalty keys.
     * ARGV: now, member, retention cutoff, ttl (s), set count, limit count, then per limit:
     * set index, max attempts, inclusive lower bound of the window, window length (ms),
     * penalty index, trim cutoff ('' = no trim).
     * Returns {allowed, delay ms, 1-based index of the limit that forced the wait, its hits}.
     * Mirrors Support\Windows::evaluate(). Lua 5.1 only — it is what Redis embeds.
     */
    protected const ATTEMPT_SCRIPT = <<<'LUA'
        local now = tonumber(ARGV[1])
        local sets = tonumber(ARGV[5])
        local limits = tonumber(ARGV[6])
        local delay, strictest, held = 0, 0, 0

        for i = 1, limits do
            local base = 6 + (i - 1) * 6
            local set = KEYS[tonumber(ARGV[base + 1])]
            local max = tonumber(ARGV[base + 2])
            local since = ARGV[base + 3]
            local length = tonumber(ARGV[base + 4])
            local count = redis.call('ZCOUNT', set, since, '+inf')
            local wait = 0

            if count >= max then
                local entry = redis.call('ZRANGEBYSCORE', set, since, '+inf', 'WITHSCORES', 'LIMIT', count - max, 1)
                wait = tonumber(entry[2]) + length - now
            end

            local penalty = tonumber(redis.call('GET', KEYS[tonumber(ARGV[base + 5])]) or '')

            if penalty and penalty - now > wait then
                wait = penalty - now
            end

            if wait > delay then
                delay, strictest, held = wait, i, count
            end
        end

        if delay > 0 then
            return {0, delay, strictest, held}
        end

        for s = 1, sets do
            redis.call('ZADD', KEYS[s], ARGV[1], ARGV[2])
            redis.call('ZREMRANGEBYSCORE', KEYS[s], '-inf', ARGV[3])
            redis.call('EXPIRE', KEYS[s], ARGV[4])
        end

        for i = 1, limits do
            local base = 6 + (i - 1) * 6

            if ARGV[base + 6] ~= '' then
                redis.call('ZREMRANGEBYSCORE', KEYS[tonumber(ARGV[base + 1])], '-inf', ARGV[base + 6])
            end
        end

        return {1, 0, 0, 0}
        LUA;

    /**
     * Keep the later of the stored and the new penalty, in one step. KEYS: the penalty key.
     * ARGV: the penalty timestamp (ms), ttl (s).
     */
    protected const PENALIZE_SCRIPT = <<<'LUA'
        local current = tonumber(redis.call('GET', KEYS[1]) or '')

        if current == nil or tonumber(ARGV[1]) > current then
            redis.call('SET', KEYS[1], ARGV[1])
        end

        redis.call('EXPIRE', KEYS[1], ARGV[2])

        return 1
        LUA;

    public function __construct(protected string $connection = 'default') {}

    public function attempt(array $limits, int $timestamp): AttemptResult
    {
        $sets = Windows::storeKeys($limits);
        $penalties = Windows::ownerKeys($limits);

        $keys = [
            ...array_map($this->key(...), $sets),
            ...array_map($this->penaltyKey(...), $penalties),
        ];

        $arguments = [
            (string) $timestamp,
            $this->member($timestamp),
            (string) ($timestamp - self::RETENTION_SECONDS * 1000),
            (string) self::RETENTION_SECONDS,
            (string) count($sets),
            (string) count($limits),
        ];

        foreach ($limits as $limit) {
            array_push(
                $arguments,
                (string) (array_search($limit->storeKey(), $sets, true) + 1),
                (string) $limit->getMaxAttempts(),
                (string) Windows::since($limit, $timestamp),
                (string) $limit->timespanLengthInMs(),
                (string) (count($sets) + array_search($limit->getKey(), $penalties, true) + 1),
                $limit->shouldTrim() ? (string) ($timestamp - $limit->timespanLengthInMs()) : '',
            );
        }

        $reply = $this->evaluate(self::ATTEMPT_SCRIPT, count($keys), [...$keys, ...$arguments]);

        [$allowed, $delay, $strictest, $held] = array_map(
            static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0,
            is_array($reply) ? array_values($reply) : [0, 0, 0, 0],
        ) + [0, 0, 0, 0];

        if ($allowed === 1) {
            return AttemptResult::allowed();
        }

        return AttemptResult::deferred($delay, $limits[$strictest - 1] ?? $limits[0], $held);
    }

    public function hit(string $owner, int $timestamp): void
    {
        $connection = $this->redis();

        $connection->zadd($this->key($owner), $timestamp, $this->member($timestamp));

        // Drop entries older than the largest window we support so the sorted
        // set stays bounded, and refresh a key TTL so abandoned owners expire.
        $connection->zremrangebyscore(
            $this->key($owner),
            '-inf',
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
        /** @var list<int|string> $members */
        $members = $this->redis()->zrangebyscore($this->key($owner), (string) $timestamp, '+inf');

        // A member is "{timestamp}:{random}"; its leading integer is the hit.
        return array_map(
            static fn (int|string $member): int => (int) $member,
            $members,
        );
    }

    public function clear(string $owner, int $timestamp): void
    {
        $this->redis()->zremrangebyscore($this->key($owner), '-inf', (string) $timestamp);
    }

    public function penalizeUntil(string $owner, int $timestamp): void
    {
        $this->evaluate(self::PENALIZE_SCRIPT, 1, [
            $this->penaltyKey($owner),
            (string) $timestamp,
            (string) self::RETENTION_SECONDS,
        ]);
    }

    public function penalizedUntil(string $owner): ?int
    {
        $value = $this->redis()->get($this->penaltyKey($owner));

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The sorted set of a window series ("{limit key}:{window}"), hash-tagged by its limit key.
     */
    public function key(string $owner): string
    {
        foreach (Timespan::cases() as $timespan) {
            $suffix = ':'.$timespan->value;

            if (str_ends_with($owner, $suffix) && $owner !== $suffix) {
                return 'http-client-rate-limits:{'.substr($owner, 0, -strlen($suffix)).'}'.$suffix;
            }
        }

        return 'http-client-rate-limits:{'.$owner.'}';
    }

    public function penaltyKey(string $owner): string
    {
        return 'http-client-rate-limits:{'.$owner.'}:penalty';
    }

    /**
     * A sorted-set member unique to this hit, so two hits in one millisecond both count.
     */
    protected function member(int $timestamp): string
    {
        return $timestamp.':'.Str::random(12);
    }

    /**
     * Run a Lua script. PhpRedis orders EVAL's arguments differently from Predis; its
     * Laravel connection normalizes them, so hand it the Laravel form.
     *
     * @param  list<string>  $keysAndArguments
     */
    protected function evaluate(string $script, int $numberOfKeys, array $keysAndArguments): mixed
    {
        $connection = $this->redis();

        if ($connection instanceof PhpRedisConnection) {
            return $connection->eval($script, $numberOfKeys, ...$keysAndArguments);
        }

        return $connection->command('eval', [$script, $numberOfKeys, ...$keysAndArguments]);
    }

    protected function redis(): Connection
    {
        return Redis::connection($this->connection);
    }
}
