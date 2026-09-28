<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Tests;

use RoundlyConsulting\HttpClientRateLimits\DataTransferObjects\AttemptResult;
use RoundlyConsulting\HttpClientRateLimits\Store\Store;

class TestStore implements Store
{
    public function attempt(array $limits, int $timestamp): AttemptResult
    {
        return AttemptResult::allowed();
    }

    public function hit(string $owner, int $timestamp): void
    {
        //
    }

    public function hits(string $owner): array
    {
        return [];
    }

    public function hitsSince(string $owner, int $timestamp): array
    {
        return [];
    }

    public function clear(string $owner, int $timestamp): void
    {
        //
    }

    public function penalizeUntil(string $owner, int $timestamp): void
    {
        //
    }

    public function penalizedUntil(string $owner): ?int
    {
        return null;
    }
}
