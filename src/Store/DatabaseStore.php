<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Store;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\HttpClientRateLimits\DataTransferObjects\AttemptResult;
use RoundlyConsulting\HttpClientRateLimits\Models\RateLimitHit;
use RoundlyConsulting\HttpClientRateLimits\Models\RateLimitOwner;
use RoundlyConsulting\HttpClientRateLimits\Support\Windows;

/**
 * Shares rate-limit state through two database tables, for apps that run only a
 * file/database cache and have no Redis. Backed by Eloquent (never the DB facade)
 * on the host's default connection, or the one named in `database_connection`.
 *
 * `attempt()` runs in a transaction that first writes the owner row of every limit key it
 * touches (sorted, one at a time): that write takes the row lock on MySQL/MariaDB and
 * Postgres and the database write lock on SQLite, so a second worker's attempt on the same
 * key waits until the first has checked AND recorded. Owner rows are created race-free
 * through their unique `owner` column.
 */
final class DatabaseStore implements Store
{
    /** One day (the largest supported window) plus a generous margin, in milliseconds. */
    protected const RETENTION_MS = (86_400 + 3_600) * 1_000;

    /** Retries when the engine picks this transaction as a deadlock victim. */
    protected const TRANSACTION_ATTEMPTS = 3;

    public function __construct(protected ?string $connection = null) {}

    public function attempt(array $limits, int $timestamp): AttemptResult
    {
        $result = $this->connection()->transaction(function () use ($limits, $timestamp): AttemptResult {
            $penalties = $this->lockOwners(Windows::ownerKeys($limits), $timestamp);

            $result = Windows::evaluate(
                $limits,
                $timestamp,
                $this->hitsSince(...),
                static fn (string $owner): ?int => $penalties[$owner] ?? null,
            );

            if (! $result->allowed) {
                return $result;
            }

            foreach (Windows::storeKeys($limits) as $storeKey) {
                $this->hitRows()->create(['owner' => $storeKey, 'hit_at' => $timestamp]);
            }

            foreach ($limits as $limit) {
                if ($limit->shouldTrim()) {
                    $this->clear($limit->storeKey(), $timestamp - $limit->timespanLengthInMs());
                }
            }

            return $result;
        }, self::TRANSACTION_ATTEMPTS);

        if ($result->allowed) {
            // Outside the transaction: the sweep touches other keys' rows and must never
            // wait on a lock while holding ours.
            $this->sweep($timestamp);
        }

        return $result;
    }

    public function hit(string $owner, int $timestamp): void
    {
        $this->hitRows()->create([
            'owner' => $owner,
            'hit_at' => $timestamp,
        ]);

        $this->sweep($timestamp);
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
        $hits = $this->hitRows()
            ->where('owner', $owner)
            ->where('hit_at', '>=', $timestamp)
            ->orderBy('hit_at')
            ->pluck('hit_at')
            ->all();

        $list = [];

        foreach ($hits as $hit) {
            $list[] = (int) $hit;
        }

        return $list;
    }

    public function clear(string $owner, int $timestamp): void
    {
        $this->hitRows()
            ->withTrashed()
            ->where('owner', $owner)
            ->where('hit_at', '<=', $timestamp)
            ->forceDelete();
    }

    public function penalizeUntil(string $owner, int $timestamp): void
    {
        $this->ensureOwner($owner, touchedAt: 0);

        // One conditional UPDATE keeps the later penalty without a read-modify-write race.
        $this->ownerRows()
            ->where('owner', $owner)
            ->where(static function (Builder $query) use ($timestamp): void {
                $query->whereNull('penalized_until')->orWhere('penalized_until', '<', $timestamp);
            })
            ->update(['penalized_until' => $timestamp]);
    }

    public function penalizedUntil(string $owner): ?int
    {
        $value = $this->ownerRows()->where('owner', $owner)->value('penalized_until');

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Take the lock on each owner row, in the given (sorted) order so overlapping attempts
     * can never deadlock, and return the penalties read under it.
     *
     * @param  list<string>  $owners
     * @return array<string, int|null>
     */
    protected function lockOwners(array $owners, int $timestamp): array
    {
        $penalties = [];

        foreach ($owners as $owner) {
            if ($this->touch($owner, $timestamp) === 0) {
                // First use of this key: create its row (a concurrent creator loses on the
                // unique index and reads ours), then write it to hold the lock.
                $this->ensureOwner($owner, $timestamp);
                $this->touch($owner, $timestamp);
            }

            $value = $this->ownerRows()->where('owner', $owner)->value('penalized_until');
            $penalties[$owner] = is_numeric($value) ? (int) $value : null;
        }

        return $penalties;
    }

    protected function touch(string $owner, int $timestamp): int
    {
        return $this->ownerRows()->where('owner', $owner)->update(['touched_at' => $timestamp]);
    }

    protected function ensureOwner(string $owner, int $touchedAt): void
    {
        $this->ownerRows()->createOrFirst(['owner' => $owner], ['touched_at' => $touchedAt]);
    }

    /**
     * Drop hits (of any owner) older than the largest window, and owner rows nothing has
     * used for as long and whose penalty has passed, so both tables stay bounded.
     */
    protected function sweep(int $timestamp): void
    {
        $this->hitRows()
            ->withTrashed()
            ->where('hit_at', '<', $timestamp - self::RETENTION_MS)
            ->forceDelete();

        $this->ownerRows()
            ->where('touched_at', '<', $timestamp - self::RETENTION_MS)
            ->where(static function (Builder $query) use ($timestamp): void {
                $query->whereNull('penalized_until')->orWhere('penalized_until', '<', $timestamp);
            })
            ->forceDelete();
    }

    /**
     * @return Builder<RateLimitHit>
     */
    protected function hitRows(): Builder
    {
        return RateLimitHit::on($this->connection);
    }

    /**
     * Owner rows count whatever their soft-delete state: the lock must hold regardless.
     *
     * @return Builder<RateLimitOwner>
     */
    protected function ownerRows(): Builder
    {
        return RateLimitOwner::on($this->connection)->withTrashed();
    }

    protected function connection(): ConnectionInterface
    {
        return (new RateLimitHit)->setConnection($this->connection)->getConnection();
    }
}
