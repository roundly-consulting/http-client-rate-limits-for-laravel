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
}
