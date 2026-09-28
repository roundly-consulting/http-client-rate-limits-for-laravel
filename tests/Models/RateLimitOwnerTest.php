<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\HttpClientRateLimits\Models\RateLimitOwner;

it('creates an owner row through the factory', function () {
    $owner = RateLimitOwner::factory()->create(['owner' => 'acme', 'penalized_until' => 9_000]);

    expect($owner)->toBeInstanceOf(RateLimitOwner::class)
        ->owner->toBe('acme')
        ->penalized_until->toBe(9_000)
        ->and($owner->getTable())->toBe('http_client_rate_limit_owners');
});

it('casts the millisecond columns to integers', function () {
    $owner = RateLimitOwner::factory()->create(['penalized_until' => '2500', 'touched_at' => '1500']);

    expect($owner->refresh()->penalized_until)->toBe(2_500)
        ->and($owner->touched_at)->toBe(1_500);
});

it('keeps one row per owner key', function () {
    RateLimitOwner::factory()->create(['owner' => 'acme']);

    RateLimitOwner::factory()->create(['owner' => 'acme']);
})->throws(UniqueConstraintViolationException::class);

it('soft deletes', function () {
    $owner = RateLimitOwner::factory()->create();

    $owner->delete();

    expect(class_uses($owner))->toContain(SoftDeletes::class)
        ->and(RateLimitOwner::query()->count())->toBe(0)
        ->and(RateLimitOwner::withTrashed()->count())->toBe(1);
});
