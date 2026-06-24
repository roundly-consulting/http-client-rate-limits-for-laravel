<?php

declare(strict_types=1);

use RoundlyConsulting\HttpClientRateLimits\Jitter\RandomRandomizer;

it('returns a value within the requested range', function () {
    $randomizer = new RandomRandomizer;

    foreach (range(1, 50) as $ignored) {
        $value = $randomizer->between(-10, 10);

        expect($value)->toBeGreaterThanOrEqual(-10)
            ->toBeLessThanOrEqual(10);
    }
});

it('returns the bound when min is not below max', function () {
    $randomizer = new RandomRandomizer;

    expect($randomizer->between(7, 7))->toBe(7)
        ->and($randomizer->between(9, 3))->toBe(9);
});
