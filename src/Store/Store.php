<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Store;

use RoundlyConsulting\HttpClientRateLimits\DataTransferObjects\AttemptResult;
use RoundlyConsulting\HttpClientRateLimits\Limit;

interface Store
{
    /**
     * Check every window at `$timestamp` and, only when none is full and no penalty is
     * active, record one hit per distinct store key (then trim the windows that ask for it)
     * — as ONE atomic step, so two workers can never both take the last free slot. When the
     * request may not go yet, record nothing and say how long to wait.
     *
     * The window arithmetic lives in `Support\Windows::evaluate()`; a store only has to make
     * the reads and the write indivisible (a lock, a transaction, a server-side script).
     *
     * @param  list<Limit>  $limits
     */
    public function attempt(array $limits, int $timestamp): AttemptResult;

    /**
     * Record a hit unconditionally (no check).
     */
    public function hit(string $owner, int $timestamp): void;

    /**
     * @return list<int> every recorded hit, oldest first
     */
    public function hits(string $owner): array;

    /**
     * @return list<int> the hits at or after `$timestamp`, oldest first
     */
    public function hitsSince(string $owner, int $timestamp): array;

    /**
     * Forget the hits at or before `$timestamp`.
     */
    public function clear(string $owner, int $timestamp): void;

    /**
     * Record a server-imposed "do not send again before" timestamp (ms), so an
     * adaptive limiter can honour response headers like Retry-After / X-RateLimit-Reset.
     * Keeps the later of an existing penalty and the new one.
     */
    public function penalizeUntil(string $owner, int $timestamp): void;

    /**
     * The active penalty timestamp (ms) for the owner, or null when none is set.
     */
    public function penalizedUntil(string $owner): ?int;
}
