<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\HttpClientRateLimits\Models\RateLimitHit;

/** @extends Factory<RateLimitHit> */
final class RateLimitHitFactory extends Factory
{
    protected $model = RateLimitHit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner' => 'global',
            'hit_at' => now()->getTimestampMs(),
            'penalized_until' => null,
        ];
    }
}
