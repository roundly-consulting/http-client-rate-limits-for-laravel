<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Store;

interface Store
{
    public function hit(string $owner, int $timestamp): void;

    /**
     * @return list<int>
     */
    public function hits(string $owner): array;

    /**
     * @return list<int>
     */
    public function hitsSince(string $owner, int $timestamp): array;

    public function clear(string $owner, int $timestamp): void;

    /**
     * Record a server-imposed "do not send again before" timestamp (ms), so an
     * adaptive limiter can honour response headers like Retry-After / X-RateLimit-Reset.
     */
    public function penalizeUntil(string $owner, int $timestamp): void;

    /**
     * The active penalty timestamp (ms) for the owner, or null when none is set.
     */
    public function penalizedUntil(string $owner): ?int;
}
