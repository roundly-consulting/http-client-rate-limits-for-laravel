<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use RoundlyConsulting\HttpClientRateLimits\Models\RateLimitHit;

uses(RefreshDatabase::class);

it('creates a hit through the factory', function () {
    $hit = RateLimitHit::factory()->create(['owner' => 'acme', 'hit_at' => 1_000]);

    expect($hit)->toBeInstanceOf(RateLimitHit::class)
        ->owner->toBe('acme')
        ->hit_at->toBe(1_000)
        ->and($hit->getTable())->toBe('http_client_rate_limits');
});

it('casts the timestamp column to an integer', function () {
    $hit = RateLimitHit::factory()->create([
        'owner' => 'acme',
        'hit_at' => '1500',
    ]);

    expect($hit->refresh()->hit_at)->toBe(1_500);
});

it('soft deletes', function () {
    $hit = RateLimitHit::factory()->create();

    $hit->delete();

    expect(RateLimitHit::query()->count())->toBe(0)
        ->and(RateLimitHit::withTrashed()->count())->toBe(1);
});
