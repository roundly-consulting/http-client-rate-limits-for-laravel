<?php

declare(strict_types=1);

use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\RecordingDeferrer;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\Limiter;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\HttpClientRateLimits\Store\RedisStore;
use RoundlyConsulting\HttpClientRateLimits\Tests\Support\LuaRedisConnection;

/*
 * Every case runs twice:
 *
 *  - "redis-server": a real Redis (CI runs one; skipped where none is reachable);
 *  - "lua harness": LuaRedisConnection — for machines with no redis-server. Every command,
 *    and the store's real Lua script, runs in a real Lua interpreter against a redis.call()
 *    with Redis's semantics (skipped where no `lua` is on PATH; CI installs lua5.1, the
 *    dialect Redis embeds).
 *
 * Owners are random per test, so a real Redis shared with anything else is never touched
 * beyond the keys this suite created (and deletes again).
 */
uses()->group('redis');

dataset('redis backends', ['redis-server', 'lua harness']);

/**
 * The real-Redis keys this suite created, to delete after each test.
 *
 * @param  list<string>  $add
 * @return list<string>
 */
function redisKeysToForget(array $add = [], bool $flush = false): array
{
    static $keys = [];

    $keys = [...$keys, ...$add];
    $current = $keys;

    if ($flush) {
        $keys = [];
    }

    return $current;
}

function redisBackend(string $backend): ?LuaRedisConnection
{
    if ($backend === 'lua harness') {
        if (LuaRedisConnection::binary() === null) {
            test()->markTestSkipped('No Lua interpreter on PATH.');
        }

        $connection = new LuaRedisConnection;

        Redis::swap(new class($connection)
        {
            public function __construct(private readonly LuaRedisConnection $connection) {}

            public function connection(?string $name = null): LuaRedisConnection
            {
                return $this->connection;
            }
        });

        return $connection;
    }

    try {
        Redis::connection()->ping();
    } catch (Throwable $e) {
        test()->markTestSkipped('Redis connection is not available: '.$e->getMessage());
    }

    return null;
}

/**
 * A fresh owner key, remembered so a real Redis gets its keys deleted afterwards.
 */
function redisOwner(string $name): string
{
    $owner = $name.'-'.Str::random(8);
    $store = new RedisStore;

    redisKeysToForget([
        ...array_map(
            static fn (string $suffix): string => $store->key($owner.$suffix),
            ['', ':second', ':minute', ':hour', ':day'],
        ),
        $store->penaltyKey($owner),
    ]);

    return $owner;
}

afterEach(function () {
    $keys = redisKeysToForget(flush: true);

    if ($keys === [] || ! Redis::getFacadeRoot() instanceof RedisManager) {
        return; // the Lua harness wrote nothing to a real Redis
    }

    try {
        foreach ($keys as $key) {
            Redis::connection()->del($key);
        }
    } catch (Throwable) {
        // No reachable Redis: nothing was written to one.
    }
});

it('stores and returns hits', function (string $backend) {
    redisBackend($backend);
    $store = new RedisStore;
    [$john, $jane] = [redisOwner('john'), redisOwner('jane')];

    expect($store->hits($john))->toBe([]);

    $store->hit($john, 12345);

    expect($store->hits($john))->toBe([12345])
        ->and($store->hits($jane))->toBe([]);
})->with('redis backends');

// Bug: the timestamp was both score and member, so same-millisecond hits collapsed into one.
it('keeps every hit recorded in the same millisecond', function (string $backend) {
    redisBackend($backend);
    $store = new RedisStore;
    $owner = redisOwner('burst');

    $store->hit($owner, 1_000);
    $store->hit($owner, 1_000);

    $limits = [new Limit($owner, 5, 'second')];

    foreach (range(1, 7) as $ignored) {
        $store->attempt($limits, 2_000);
    }

    expect($store->hits($owner))->toBe([1_000, 1_000])
        ->and($store->hits($owner.':second'))->toBe([2_000, 2_000, 2_000, 2_000, 2_000]);
})->with('redis backends');

it('defers a same-millisecond pool the way the in-memory store does', function (string $backend) {
    redisBackend($backend);
    $owner = redisOwner('pool');

    $run = static function ($store) use ($owner): array {
        $deferrer = new RecordingDeferrer(1_000_000);
        $limiter = new Limiter(new Limit($owner, 5, 'second'), $store, $deferrer);

        foreach (range(1, 20) as $ignored) {
            $limiter->handle(fn () => null);
        }

        return [$deferrer->deferCount(), count($store->hits($owner.':second'))];
    };

    // Five go at once, then each wait frees a second's worth: 3 waits for 20 requests.
    expect($run(new RedisStore))->toBe($run(new InMemoryStore))->toBe([3, 20]);
})->with('redis backends');

it('returns hits since a given timestamp', function (string $backend) {
    redisBackend($backend);
    $store = new RedisStore;
    [$john, $jane] = [redisOwner('john'), redisOwner('jane')];

    $store->hit($john, 10);
    $store->hit($john, 15);
    $store->hit($john, 20);
    $store->hit($jane, 15);

    expect($store->hitsSince($john, 10))->toBe([10, 15, 20])
        ->and($store->hitsSince($jane, 10))->toBe([15])
        ->and($store->hitsSince($john, 15))->toBe([15, 20]);
})->with('redis backends');

it('sets a bounded ttl on the key when recording a hit', function (string $backend) {
    $harness = redisBackend($backend);
    $store = new RedisStore;
    $owner = redisOwner('ttl');

    $store->hit($owner, now()->getTimestampMs());
    $store->attempt([new Limit($owner, 5, 'minute')], now()->getTimestampMs());

    $ttl = static fn (string $key): int => $harness?->ttls[$key] ?? (int) Redis::connection()->ttl($key);

    expect($ttl($store->key($owner)))->toBeGreaterThan(0)->toBeLessThanOrEqual(90_000)
        ->and($ttl($store->key($owner.':minute')))->toBeGreaterThan(0)->toBeLessThanOrEqual(90_000);
})->with('redis backends');

it('clears recorded hits by timestamp', function (string $backend) {
    redisBackend($backend);
    $store = new RedisStore;
    [$john, $jane] = [redisOwner('john'), redisOwner('jane')];

    $store->hit($john, 10);
    $store->hit($john, 15);
    $store->hit($john, 20);
    $store->hit($jane, 10);

    $store->clear($john, 15);

    expect($store->hits($john))->toBe([20])
        ->and($store->hits($jane))->toBe([10]);
})->with('redis backends');

it('records and reads penalties keeping the latest', function (string $backend) {
    redisBackend($backend);
    $store = new RedisStore;
    $owner = redisOwner('penalty');

    expect($store->penalizedUntil($owner))->toBeNull();

    $store->penalizeUntil($owner, 5_000);
    expect($store->penalizedUntil($owner))->toBe(5_000);

    $store->penalizeUntil($owner, 4_000);
    expect($store->penalizedUntil($owner))->toBe(5_000);

    $store->penalizeUntil($owner, 9_000);
    expect($store->penalizedUntil($owner))->toBe(9_000);
})->with('redis backends');

it('refuses an attempt across compound windows, recording nothing, then records in each', function (string $backend) {
    redisBackend($backend);
    $store = new RedisStore;
    $owner = redisOwner('compound');
    $perSecond = new Limit($owner, 5, 'second');
    $perMinute = new Limit($owner, 2, 'minute');

    expect($store->attempt([$perSecond, $perMinute], 1_000)->allowed)->toBeTrue()
        ->and($store->attempt([$perSecond, $perMinute], 2_000)->allowed)->toBeTrue();

    $refused = $store->attempt([$perSecond, $perMinute], 3_000);

    expect($refused->allowed)->toBeFalse()
        ->and($refused->delayMs)->toBe(58_000)
        ->and($refused->limit)->toBe($perMinute)
        ->and($refused->hitsInWindow)->toBe(2)
        ->and($store->hits($owner.':second'))->toBe([1_000, 2_000])
        ->and($store->hits($owner.':minute'))->toBe([1_000, 2_000])
        ->and($store->attempt([$perSecond, $perMinute], 61_000)->allowed)->toBeTrue()
        ->and($store->hits($owner.':minute'))->toBe([1_000, 2_000, 61_000]);
})->with('redis backends');

it('waits for hit [count - max] when a window is over-full', function (string $backend) {
    redisBackend($backend);
    $store = new RedisStore;
    $owner = redisOwner('overfull');

    foreach ([0, 10, 20, 30] as $offset) {
        $store->hit($owner.':second', 1_000_000 + $offset);
    }

    $refused = $store->attempt([new Limit($owner, 2, 'second')], 1_000_040);

    // Hits +0, +10 and +20 must expire (the last at +1020) for fewer than 2 to remain.
    expect($refused->delayMs)->toBe(980)
        ->and($refused->hitsInWindow)->toBe(4);
})->with('redis backends');

it('refuses while a penalty is in force and allows once it passes', function (string $backend) {
    redisBackend($backend);
    $store = new RedisStore;
    $owner = redisOwner('penalized');
    $limits = [new Limit($owner, 5, 'second')];

    $store->penalizeUntil($owner, 10_000);

    $refused = $store->attempt($limits, 3_000);

    expect($refused->allowed)->toBeFalse()
        ->and($refused->delayMs)->toBe(7_000)
        ->and($refused->hitsInWindow)->toBe(0)
        ->and($store->attempt($limits, 10_000)->allowed)->toBeTrue();
})->with('redis backends');

it('trims a window that asks for it once the hit is recorded', function (string $backend) {
    redisBackend($backend);
    $store = new RedisStore;
    $owner = redisOwner('trimmed');

    $store->hit($owner.':second', 1_000);
    $store->hit($owner.':second', 2_500);

    expect($store->attempt([(new Limit($owner, 5, 'second'))->trim()], 3_000)->allowed)->toBeTrue()
        ->and($store->hits($owner.':second'))->toBe([2_500, 3_000]);
})->with('redis backends');

it('agrees with the in-memory store on a mixed sequence of attempts', function (string $backend) {
    redisBackend($backend);
    $owner = redisOwner('parity');
    $limits = [new Limit($owner, 3, 'second'), new Limit($owner, 5, 'minute')];
    $redis = new RedisStore;
    $memory = new InMemoryStore;

    $outcomes = static function ($store) use ($limits): array {
        $seen = [];

        foreach ([0, 0, 100, 400, 900, 1_000, 1_000, 1_001, 1_500, 2_200, 30_000, 61_000] as $at) {
            $result = $store->attempt($limits, 1_000_000 + $at);
            $seen[] = [$result->allowed, $result->delayMs, $result->limit?->getTimespan(), $result->hitsInWindow];
        }

        return $seen;
    };

    expect($outcomes($redis))->toBe($outcomes($memory));
})->with('redis backends');

it('hash-tags every key by its limit key so a limit stays in one cluster slot', function () {
    $store = new RedisStore;

    expect($store->key('acct-1:second'))->toBe('http-client-rate-limits:{acct-1}:second')
        ->and($store->key('a:b:minute'))->toBe('http-client-rate-limits:{a:b}:minute')
        ->and($store->key('john'))->toBe('http-client-rate-limits:{john}')
        ->and($store->penaltyKey('acct-1'))->toBe('http-client-rate-limits:{acct-1}:penalty');
});
