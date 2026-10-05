<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\Limiter;
use RoundlyConsulting\HttpClientRateLimits\Models\RateLimitHit;
use RoundlyConsulting\HttpClientRateLimits\Store\CacheStore;
use RoundlyConsulting\HttpClientRateLimits\Store\DatabaseStore;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\HttpClientRateLimits\Store\RedisStore;
use RoundlyConsulting\HttpClientRateLimits\Store\Store;
use RoundlyConsulting\HttpClientRateLimits\Tests\Support\LuaRedisConnection;
use RoundlyConsulting\Testing\Database\DriverMatrix;

uses(RefreshDatabase::class);

/**
 * A clock shared by two simulated workers. `$during` runs once, inside the next defer —
 * i.e. while the first worker is asleep — which is exactly where the other worker slips in.
 */
function sharedClock(int $now): Deferrer
{
    return new class($now) implements Deferrer
    {
        public ?Closure $during = null;

        /** @var list<int> */
        public array $defers = [];

        public function __construct(public int $now) {}

        public function timestamp(): int
        {
            return $this->now;
        }

        public function defer(int $ms, string $key): void
        {
            $this->defers[] = $ms;

            if ($this->during !== null) {
                $during = $this->during;
                $this->during = null;
                $during();
            }

            $this->now += $ms;
        }
    };
}

/**
 * The most requests found inside any one window-length span of the hits.
 *
 * @param  list<int>  $hits
 */
function busiestWindow(array $hits, int $length): int
{
    sort($hits);
    $busiest = 0;

    foreach ($hits as $i => $start) {
        $busiest = max($busiest, count(array_filter(
            array_slice($hits, $i),
            static fn (int $hit): bool => $hit < $start + $length,
        )));
    }

    return $busiest;
}

// Bug: A and B wait for the same freed slot; A slept on a stale check and sent on waking.
it('re-checks after waiting so two workers never both take one freed slot', function () {
    $store = new InMemoryStore;
    $clock = sharedClock(1_000_000);
    $workerA = new Limiter(new Limit('w', 1, 'second'), $store, $clock);
    $workerB = new Limiter(new Limit('w', 1, 'second'), $store, $clock);

    $workerA->handle(fn () => null);   // the window is now full (1/sec)
    $clock->now += 100;

    // While A sleeps for the slot, B arrives, waits for it too and takes it first.
    $clock->during = fn () => $workerB->handle(fn () => null);
    $workerA->handle(fn () => null);

    $hits = $store->hits('w:second');

    expect($hits)->toBe([1_000_000, 1_001_000, 1_002_000])
        ->and(busiestWindow($hits, 1_000))->toBe(1);
});

it('keeps every window within budget when many workers contend for one store', function () {
    $store = new InMemoryStore;
    $clock = sharedClock(1_000_000);
    $workers = array_map(
        static fn (): Limiter => new Limiter(new Limit('crowd', 2, 'second'), $store, $clock),
        range(1, 4),
    );

    // Each worker, while asleep, lets the next one in — a cascade of stale wake-ups.
    foreach ($workers as $i => $worker) {
        $next = $workers[$i + 1] ?? null;
        $clock->during = $next === null ? null : fn () => $next->handle(fn () => null);
        $worker->handle(fn () => null);
        $worker->handle(fn () => null);
        $worker->handle(fn () => null);
    }

    expect(busiestWindow($store->hits('crowd:second'), 1_000))->toBeLessThanOrEqual(2);
});

it('records nothing when an attempt is refused', function (Store $store) {
    $limits = [new Limit('solo', 1, 'minute')];

    expect($store->attempt($limits, 1_000)->allowed)->toBeTrue();

    $refused = $store->attempt($limits, 2_000);

    expect($refused->allowed)->toBeFalse()
        ->and($refused->delayMs)->toBe(59_000)
        ->and($refused->limit)->toBe($limits[0])
        ->and($refused->hitsInWindow)->toBe(1)
        ->and($store->hits('solo:minute'))->toBe([1_000]);
})->with([
    'in memory' => fn (): Store => new InMemoryStore,
    'cache' => fn (): Store => new CacheStore(store: 'array'),
    'database' => fn (): Store => new DatabaseStore,
]);

/**
 * The CacheStore holds one lock per window across the whole check-then-record. Simulated
 * interleaving: a second worker's attempt, made while the first is between its read and its
 * write, cannot get the lock — and once the first is done, it sees the taken slot.
 */
it('locks the cache windows across the check and the record', function () {
    $backend = new class extends ArrayStore
    {
        public ?Closure $onRead = null;

        public function get($key)
        {
            if ($this->onRead !== null && str_ends_with((string) $key, 'race:second')) {
                $hook = $this->onRead;
                $this->onRead = null;
                $hook();
            }

            return parent::get($key);
        }
    };

    Cache::extend('hooked', fn () => new Repository($backend));
    config()->set('cache.stores.hooked', ['driver' => 'hooked']);

    $limits = [new Limit('race', 1, 'second')];
    $workerA = new CacheStore(store: 'hooked');
    $workerB = new CacheStore(store: 'hooked', lockSeconds: 0);
    $blocked = null;

    $backend->onRead = function () use ($workerB, $limits, &$blocked): void {
        try {
            $workerB->attempt($limits, 5_000);
        } catch (LockTimeoutException $exception) {
            $blocked = $exception;
        }
    };

    expect($workerA->attempt($limits, 5_000)->allowed)->toBeTrue()
        ->and($blocked)->toBeInstanceOf(LockTimeoutException::class)
        ->and($workerB->attempt($limits, 5_000)->allowed)->toBeFalse()
        ->and($workerA->hits('race:second'))->toBe([5_000]);
});

/**
 * The DatabaseStore takes its lock with a write to the owner row, before it reads a single
 * hit, inside one transaction — pinned on whatever engine the leg runs.
 */
it('locks the owner row before it reads any hit, inside one transaction', function () {
    $connection = (new RateLimitHit)->getConnection();
    $baseLevel = $connection->transactionLevel();
    $log = [];

    $connection->listen(function ($query) use (&$log, $connection): void {
        $log[] = [strtolower(strtok($query->sql, ' ')), $query->sql, $connection->transactionLevel()];
    });

    (new DatabaseStore)->attempt([new Limit('ordered', 1, 'second')], 5_000);

    $statements = array_values(array_filter(
        $log,
        // The retention sweep runs after the transaction, on purpose.
        static fn (array $entry): bool => $entry[0] !== 'delete',
    ));
    $firstHitRead = array_key_first(array_filter(
        $statements,
        // The hits table under either quoting: `"` (sqlite, postgres) or a backtick (mysql).
        static fn (array $entry): bool => $entry[0] === 'select' && preg_match('/["`]http_client_rate_limits["`]/', $entry[1]) === 1,
    ));

    expect($statements[0][0])->toBe('update')
        ->and($statements[0][1])->toContain('http_client_rate_limit_owners')
        ->and($firstHitRead)->toBeGreaterThan(0)
        ->and(min(array_column($statements, 2)))->toBeGreaterThan($baseLevel);
});

/**
 * The real thing on SQLite: two connections to one database file. While worker A is inside
 * its attempt, worker B's attempt cannot take the write lock (busy_timeout 0 makes it fail
 * instead of waiting on a lock this same process holds) — and once A commits, B sees A's hit.
 */
it('serializes database attempts across connections', function () {
    $file = tempnam(sys_get_temp_dir(), 'hcrl-').'.sqlite';
    touch($file);

    foreach (['hcrl_a' => 60_000, 'hcrl_b' => 0] as $name => $busyTimeout) {
        config()->set("database.connections.{$name}", [
            'driver' => 'sqlite',
            'database' => $file,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => $busyTimeout,
        ]);
    }

    Artisan::call('migrate', [
        '--database' => 'hcrl_a',
        '--path' => realpath(__DIR__.'/../../database/migrations'),
        '--realpath' => true,
    ]);

    $limits = [new Limit('shared', 1, 'minute')];
    $workerA = new DatabaseStore('hcrl_a');
    $workerB = new DatabaseStore('hcrl_b');
    $blocked = null;
    $fired = false;

    (new RateLimitHit)->setConnection('hcrl_a')->getConnection()->listen(
        function ($query) use ($workerB, $limits, &$blocked, &$fired): void {
            if ($fired || ! str_starts_with(strtolower($query->sql), 'select')) {
                return;
            }

            $fired = true;

            try {
                $workerB->attempt($limits, 5_000);
            } catch (QueryException $exception) {
                $blocked = $exception;
            }
        },
    );

    try {
        expect($workerA->attempt($limits, 5_000)->allowed)->toBeTrue()
            ->and($blocked?->getMessage())->toContain('database is locked')
            ->and($workerB->attempt($limits, 5_000)->allowed)->toBeFalse()
            ->and($workerB->hits('shared:minute'))->toBe([5_000]);
    } finally {
        (new RateLimitHit)->setConnection('hcrl_a')->getConnection()->disconnect();
        (new RateLimitHit)->setConnection('hcrl_b')->getConnection()->disconnect();
        @unlink($file);
    }
})->skip(fn (): bool => DriverMatrix::driver() !== 'sqlite', 'the two-connection interleaving runs on the sqlite leg');

/**
 * Redis executes a script without interleaving any other client's command, so the
 * RedisStore's atomicity is structural: one attempt is exactly one EVAL — no read before it,
 * no write after it.
 */
it('takes a redis slot in a single script call', function () {
    $connection = new LuaRedisConnection;
    Redis::swap(new class($connection)
    {
        public function __construct(private readonly LuaRedisConnection $connection) {}

        public function connection(?string $name = null): LuaRedisConnection
        {
            return $this->connection;
        }
    });

    $store = new RedisStore;
    $limits = [new Limit('one-call', 1, 'second'), new Limit('one-call', 5, 'minute')];

    expect($store->attempt($limits, 5_000)->allowed)->toBeTrue()
        ->and($connection->log)->toBe(['EVAL'])
        ->and($store->attempt($limits, 5_100)->allowed)->toBeFalse()
        ->and($connection->log)->toBe(['EVAL', 'EVAL']);
})->skip(fn (): bool => LuaRedisConnection::binary() === null, 'no Lua interpreter on PATH');

it('agrees with the in-memory store on a mixed sequence of attempts', function (Store $store) {
    $limits = [
        new Limit('parity', 3, 'second'),
        (new Limit('parity', 5, 'minute'))->trim(),
        new Limit('other', 4, 'hour'),
    ];
    $memory = new InMemoryStore;

    $outcomes = static function (Store $store) use ($limits): array {
        $store->penalizeUntil('other', 1_000_950);
        $seen = [];

        foreach ([0, 0, 100, 400, 900, 950, 1_000, 1_001, 1_500, 2_200, 30_000, 61_000, 62_000] as $at) {
            $result = $store->attempt($limits, 1_000_000 + $at);
            $seen[] = [$result->allowed, $result->delayMs, $result->limit?->storeKey(), $result->hitsInWindow];
        }

        return [$seen, $store->hits('parity:second'), $store->hits('parity:minute'), $store->hits('other:hour')];
    };

    expect($outcomes($store))->toBe($outcomes($memory));
})->with([
    'cache' => fn (): Store => new CacheStore(store: 'array'),
    'database' => fn (): Store => new DatabaseStore,
]);
