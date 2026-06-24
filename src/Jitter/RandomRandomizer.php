<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Jitter;

/**
 * Default randomness source for jitter. Uses random_int so spreads are
 * unpredictable; tests inject a deterministic Randomizer instead.
 */
final class RandomRandomizer implements Randomizer
{
    public function between(int $min, int $max): int
    {
        if ($min >= $max) {
            return $min;
        }

        return random_int($min, $max);
    }
}
