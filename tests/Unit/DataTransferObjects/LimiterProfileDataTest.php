<?php

declare(strict_types=1);

use RoundlyConsulting\HttpClientRateLimits\DataTransferObjects\LimiterProfileData;
use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;

it('builds from a minimal config with defaults', function () {
    $data = LimiterProfileData::fromConfig([]);

    expect($data->rate)->toBe(1)
        ->and($data->per)->toBe(Timespan::Minute)
        ->and($data->by)->toBeNull()
        ->and($data->trim)->toBeFalse()
        ->and($data->maxWaitMs)->toBeNull()
        ->and($data->jitterMs)->toBe(0)
        ->and($data->adaptive)->toBeFalse();
});

it('builds from a full config array', function () {
    $data = LimiterProfileData::fromConfig([
        'rate' => 5,
        'per' => 'second',
        'by' => 'github',
        'trim' => true,
        'max_wait' => 2_000,
        'jitter' => 50,
        'adaptive' => true,
    ]);

    expect($data->rate)->toBe(5)
        ->and($data->per)->toBe(Timespan::Second)
        ->and($data->by)->toBe('github')
        ->and($data->trim)->toBeTrue()
        ->and($data->maxWaitMs)->toBe(2_000)
        ->and($data->jitterMs)->toBe(50)
        ->and($data->adaptive)->toBeTrue();
});

it('accepts a Timespan enum for the per key', function () {
    $data = LimiterProfileData::fromConfig(['rate' => 3, 'per' => Timespan::Hour]);

    expect($data->per)->toBe(Timespan::Hour);
});

it('converts to a configured Limit', function () {
    $limit = LimiterProfileData::fromConfig([
        'rate' => 5,
        'per' => 'second',
        'by' => 'github',
        'trim' => true,
        'max_wait' => 2_000,
        'jitter' => 50,
        'adaptive' => true,
    ])->toLimit();

    expect($limit->getMaxAttempts())->toBe(5)
        ->and($limit->getTimespan())->toBe('second')
        ->and($limit->getKey())->toBe('github')
        ->and($limit->shouldTrim())->toBeTrue()
        ->and($limit->getMaxWait())->toBe(2_000)
        ->and($limit->getJitter())->toBe(50)
        ->and($limit->isAdaptive())->toBeTrue();
});

it('defaults the limit key to global without a by', function () {
    $limit = LimiterProfileData::fromConfig(['rate' => 2, 'per' => 'minute'])->toLimit();

    expect($limit->getKey())->toBe('global')
        ->and($limit->getMaxWait())->toBeNull()
        ->and($limit->getJitter())->toBe(0)
        ->and($limit->isAdaptive())->toBeFalse();
});
