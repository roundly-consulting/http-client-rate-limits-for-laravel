<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\HttpClientRateLimits\Models\RateLimitOwner;

/** @extends Factory<RateLimitOwner> */
final class RateLimitOwnerFactory extends Factory
{
    protected $model = RateLimitOwner::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner' => 'owner-'.$this->faker->unique()->numberBetween(1, 1_000_000),
            'penalized_until' => null,
            'touched_at' => now()->getTimestampMs(),
        ];
    }
}
