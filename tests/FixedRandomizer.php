<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Tests;

use RoundlyConsulting\HttpClientRateLimits\Jitter\Randomizer;

/**
 * Deterministic randomness source for jitter tests: always returns a fixed value.
 */
final class FixedRandomizer implements Randomizer
{
    public function __construct(private readonly int $value) {}

    public function between(int $min, int $max): int
    {
        return $this->value;
    }
}
