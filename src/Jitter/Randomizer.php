<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Jitter;

interface Randomizer
{
    /**
     * Return a random integer between $min and $max inclusive.
     */
    public function between(int $min, int $max): int;
}
