<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\HttpClientRateLimits\HttpClientRateLimitsServiceProvider;
use RoundlyConsulting\HttpClientRateLimits\Models\RateLimitHit;
use RoundlyConsulting\PackageToolkit\Enums\DatabaseDriver;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * This package ships exactly one CREATE and zero foreign keys — `http_client_rate_limits`
 * is keyed by an opaque `owner` string, deliberately unconstrained because the owner is
 * whatever the host chooses to bucket by (a host, a tenant, an API token).
 *
 * That shape decides what is worth pinning here, and it is worth being explicit about why
 * two assertions are absent:
 *
 *  - **M (`toHaveRunnableMigrationOrder`) is not adopted.** With one migration and no FK
 *    edges there is no order to get wrong. `foreignKeys: 0` would pin a number that cannot
 *    change without a schema change, over a directory with a single file.
 *  - **The R negative control (`toRejectBrokenOrderOnConnection`) is not adoptable.** It
 *    asserts the engine *refuses* a reordered set — but reversing a one-file list is the
 *    same list, and with no foreign keys Postgres has nothing to refuse. It fails loudly by
 *    design ("the engine ACCEPTED the broken order"), which is the assertion working
 *    correctly against a shape it does not fit, not a red to chase. (Verified on the alerts
 *    row, where the same control is adopted and passes because alerts has real FK edges.)
 *
 * What remains is the half that does bite: the DDL has to be something a real engine
 * accepts.
 */
$migrations = __DIR__.'/../../database/migrations';

/**
 * P — the publish-only guards. The fleet publishes migrations timestamped rather than
 * auto-loading them; doing both runs both copies and dies on a duplicate table (bug #5, on
 * three packages). `1` pins the file count so neither check can pass over an empty or
 * relocated directory.
 */
it('never auto-loads its migration — the host publishes it', function (): void {
    expect(HttpClientRateLimitsServiceProvider::class)->toNotAutoLoadMigrations();
});

it('publishes its migration timestamp-injected into the host', function (): void {
    expect(HttpClientRateLimitsServiceProvider::class)->toPublishMigrationsTimestamped('http-client-rate-limits-migrations', 1);
});

/**
 * R — the real-engine proof. This package's DDL had never met a real engine: the suite ran
 * on SQLite for the package's whole life. `migrations: 1` pins the count, and the
 * expectation additionally fails a set that "applies cleanly" while creating no tables — an
 * empty `up()` otherwise passes and proves nothing.
 */
it('applies its migration on postgres', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('pgsql', migrations: 1);
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres connection available');

/**
 * The driver-truth pin (Wave 2's lesson). It compares the env-DECLARED driver against what
 * the connection itself answers, so a "pgsql" leg that quietly stayed on SQLite goes red
 * here rather than passing as a postgres run. It fires automatically, unlike reading a skip
 * count by hand.
 */
it('runs on the driver the environment declares', function (): void {
    expect(DatabaseDriver::current())->toBe(DatabaseDriver::from(DriverMatrix::driver()));
});

/**
 * The two `unsignedBigInteger` millisecond timestamps are the columns the drivers can
 * genuinely disagree on: SQLite reports `unsignedBigInteger()` and `integer()` alike as
 * `integer` and would not notice a 32-bit downgrade — which matters here more than most,
 * because a millisecond epoch overflows a 32-bit signed integer (in 1970 + 24 days of
 * milliseconds). Round-tripping a real millisecond timestamp on whatever engine the leg
 * configured is what proves the column is wide enough in practice.
 */
it('round-trips a millisecond timestamp too wide for a 32-bit column', function (): void {
    $farFuture = 4_102_444_800_000; // 2100-01-01 in ms — far beyond 2^31.

    $hit = RateLimitHit::query()->create([
        'owner' => 'acme',
        'hit_at' => $farFuture,
        'penalized_until' => $farFuture,
    ]);

    $fresh = $hit->fresh();

    expect((int) $fresh->hit_at)->toBe($farFuture)
        ->and((int) $fresh->penalized_until)->toBe($farFuture)
        ->and(DB::connection()->getDriverName())->toBe(DriverMatrix::driver());
});
