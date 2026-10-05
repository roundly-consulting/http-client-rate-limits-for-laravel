<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\Models\RateLimitHit;
use RoundlyConsulting\HttpClientRateLimits\Models\RateLimitOwner;
use RoundlyConsulting\HttpClientRateLimits\Store\DatabaseStore;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/*
 * Two real sessions on the suite's own database: `hcrl_w1` runs the attempt under test and
 * `hcrl_w2` plays another worker. Not RefreshDatabase: both sessions must see each other's
 * commits, and the real-engine teardown drops every table afterwards.
 */

/**
 * Register the two sessions; the caller purges them.
 */
function twoSessions(): void
{
    foreach (['hcrl_w1', 'hcrl_w2'] as $name) {
        config()->set("database.connections.{$name}", DriverMatrix::connectionConfig());
    }
}

/**
 * What another worker's allowed attempt on `$owner` leaves behind: under that key's lock, one
 * hit on `$storeKey`, committed.
 */
function otherWorkerHits(string $owner, string $storeKey, int $at): void
{
    DB::connection('hcrl_w2')->transaction(function () use ($owner, $storeKey, $at): void {
        RateLimitOwner::on('hcrl_w2')->where('owner', $owner)->update(['touched_at' => $at]);
        RateLimitHit::on('hcrl_w2')->create(['owner' => $storeKey, 'hit_at' => $at]);
    });
}

// Bug (MySQL/MariaDB at REPEATABLE READ): the plain penalty read under the first owner's lock
// fixed the transaction's snapshot, so a hit another worker committed under a later owner's
// lock stayed invisible once that lock was won — the compound limit let a second request in.
it('sees hits committed under a later owner lock, not a stale snapshot', function () {
    twoSessions();
    $now = 1_790_000_000_000;

    // Both owner rows exist, so the attempt's first statement takes the acct-1 lock.
    RateLimitOwner::on('hcrl_w1')->create(['owner' => 'acct-1', 'touched_at' => 0]);
    RateLimitOwner::on('hcrl_w1')->create(['owner' => 'api', 'touched_at' => 0]);

    $fired = false;

    DB::connection('hcrl_w1')->listen(function (QueryExecuted $query) use (&$fired, $now): void {
        if ($fired || $query->connectionName !== 'hcrl_w1' || ! str_starts_with(strtolower($query->sql), 'select')) {
            return;
        }

        // Holding only the acct-1 lock: the other worker takes the api slot and commits.
        $fired = true;
        otherWorkerHits('api', 'api:minute', $now);
    });

    try {
        $result = (new DatabaseStore('hcrl_w1'))->attempt([
            new Limit('acct-1', 5, 'second'),
            new Limit('api', 1, 'minute'),
        ], $now);

        expect($fired)->toBeTrue()
            ->and($result->allowed)->toBeFalse()
            ->and($result->limit?->storeKey())->toBe('api:minute')
            ->and(RateLimitHit::on('hcrl_w2')->where('owner', 'api:minute')->count())->toBe(1);
    } finally {
        DB::purge('hcrl_w1');
        DB::purge('hcrl_w2');
    }
})->skip(fn (): bool => DriverMatrix::driver() === 'sqlite', 'two sessions on one database need a real engine');

// The same root cause through the host: an attempt nested in a transaction the host has already
// read in (no dedicated database_connection) must still see the hits committed since.
it('sees hits committed after the host transaction it runs in first read', function () {
    twoSessions();
    $now = 1_790_000_000_000;

    RateLimitOwner::on('hcrl_w1')->create(['owner' => 'solo', 'touched_at' => 0]);

    try {
        $result = DB::connection('hcrl_w1')->transaction(function () use ($now) {
            RateLimitHit::on('hcrl_w1')->count(); // the host reads first
            otherWorkerHits('solo', 'solo:minute', $now);

            return (new DatabaseStore('hcrl_w1'))->attempt([new Limit('solo', 1, 'minute')], $now);
        });

        expect($result->allowed)->toBeFalse()
            ->and(RateLimitHit::on('hcrl_w2')->where('owner', 'solo:minute')->count())->toBe(1);
    } finally {
        DB::purge('hcrl_w1');
        DB::purge('hcrl_w2');
    }
})->skip(fn (): bool => DriverMatrix::driver() === 'sqlite', 'two sessions on one database need a real engine');
