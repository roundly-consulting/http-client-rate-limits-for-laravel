<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Support;

use Closure;
use RoundlyConsulting\HttpClientRateLimits\DataTransferObjects\AttemptResult;
use RoundlyConsulting\HttpClientRateLimits\Limit;

/**
 * The sliding-window arithmetic shared by every store's `attempt()` and the limiter's
 * pre-flight checks, so a store can never disagree with `remaining()` / `availableIn()`.
 * A custom store implements `attempt()` as "take my lock, `evaluate()`, record if allowed".
 * (The RedisStore's Lua script mirrors it; its tests hold the two in step.)
 *
 * A hit at `h` holds its slot for `[h, h + length)`: at `now`, the window holds the hits
 * with `h > now - length`.
 */
final class Windows
{
    /**
     * The inclusive lower bound for `Store::hitsSince()` that selects the hits still
     * occupying the limit's window at `$now`.
     */
    public static function since(Limit $limit, int $now): int
    {
        return $now - $limit->timespanLengthInMs() + 1;
    }

    /**
     * Milliseconds until the window has room again. With `count` hits in it and a budget of
     * `max`, `count - max + 1` of them must expire, so the wait is for hit `[count - max]`
     * (oldest first) — not merely the oldest, which leaves an over-full window still full.
     *
     * @param  list<int>  $hits  the hits in the window
     */
    public static function delay(Limit $limit, array $hits, int $now): int
    {
        $overflow = count($hits) - $limit->getMaxAttempts();

        if ($overflow < 0) {
            return 0;
        }

        sort($hits);

        return max($hits[$overflow] + $limit->timespanLengthInMs() - $now, 0);
    }

    /**
     * Milliseconds a server-imposed penalty still asks for (0 when none is active).
     */
    public static function penaltyDelay(?int $penalizedUntil, int $now): int
    {
        return $penalizedUntil === null ? 0 : max($penalizedUntil - $now, 0);
    }

    /**
     * Decide one attempt across every window: allowed when no window is full and no
     * penalty is active, else the largest wait and the window that forced it. Pure — the
     * caller holds whatever lock makes its reads and the following write one unit.
     *
     * @param  list<Limit>  $limits
     * @param  Closure(string, int): list<int>  $hitsSince  store key + inclusive lower bound
     * @param  Closure(string): ?int  $penalizedUntil  limit key
     */
    public static function evaluate(array $limits, int $now, Closure $hitsSince, Closure $penalizedUntil): AttemptResult
    {
        $result = AttemptResult::allowed();

        foreach ($limits as $limit) {
            $hits = $hitsSince($limit->storeKey(), self::since($limit, $now));

            $delay = max(
                self::delay($limit, $hits, $now),
                self::penaltyDelay($penalizedUntil($limit->getKey()), $now),
            );

            if ($delay > $result->delayMs) {
                $result = AttemptResult::deferred($delay, $limit, count($hits));
            }
        }

        return $result;
    }

    /**
     * The distinct store keys of a set of limits, sorted — one hit per window series,
     * however many limits read it, and a stable order for taking locks.
     *
     * @param  list<Limit>  $limits
     * @return list<string>
     */
    public static function storeKeys(array $limits): array
    {
        $keys = array_values(array_unique(array_map(
            static fn (Limit $limit): string => $limit->storeKey(),
            $limits,
        )));

        sort($keys);

        return $keys;
    }

    /**
     * The distinct limit (owner) keys of a set of limits, sorted.
     *
     * @param  list<Limit>  $limits
     * @return list<string>
     */
    public static function ownerKeys(array $limits): array
    {
        $keys = array_values(array_unique(array_map(
            static fn (Limit $limit): string => $limit->getKey(),
            $limits,
        )));

        sort($keys);

        return $keys;
    }
}
