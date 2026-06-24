<?php

declare(strict_types=1);

use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidTimespanException;
use RoundlyConsulting\HttpClientRateLimits\Limit;

it('sets max attempts per second', function () {
    $limit = new Limit;

    $limit->perSecond(5);

    expect($limit)
        ->getMaxAttempts()->toBe(5)
        ->getTimespan()->toBe('second');
});

it('sets max attempts per minute', function () {
    $limit = new Limit;

    $limit->perMinute(10);

    expect($limit)
        ->getMaxAttempts()->toBe(10)
        ->getTimespan()->toBe('minute');
});

it('sets max attempts per hour', function () {
    $limit = new Limit;

    $limit->perHour(50);

    expect($limit)
        ->getMaxAttempts()->toBe(50)
        ->getTimespan()->toBe('hour');
});

it('sets max attempts and timespan', function () {
    $limit = new Limit;

    $limit->maxAttempts(40, 'hour');

    expect($limit)
        ->getMaxAttempts()->toBe(40)
        ->getTimespan()->toBe('hour');
});

it('sets key of limit', function () {
    $limit = new Limit;

    $limit->by('john');

    expect($limit)
        ->getKey()->toBe('john');
});

it('sets max attempts per day', function () {
    $limit = new Limit;

    $limit->perDay(1_000);

    expect($limit)
        ->getMaxAttempts()->toBe(1_000)
        ->getTimespan()->toBe('day')
        ->getTimespanEnum()->toBe(Timespan::Day);
});

it('returns timespan length in ms for specific timespan', function () {
    $limit = new Limit;

    $limit->perSecond(1);
    expect($limit->timespanLengthInMs())->toBe(1_000);

    $limit->perMinute(1);
    expect($limit->timespanLengthInMs())->toBe(60_000);

    $limit->perHour(1);
    expect($limit->timespanLengthInMs())->toBe(3_600_000);

    $limit->perDay(1);
    expect($limit->timespanLengthInMs())->toBe(86_400_000);
});

it('accepts a timespan enum in the constructor', function () {
    $limit = new Limit(maxAttempts: 5, timespan: Timespan::Hour);

    expect($limit->getTimespanEnum())->toBe(Timespan::Hour)
        ->and($limit->getTimespan())->toBe('hour');
});

it('throws a typed exception for an unknown timespan string', function () {
    new Limit(timespan: 'decade');
})->throws(InvalidTimespanException::class);

it('toggles the trim flag', function () {
    $limit = new Limit;

    expect($limit->shouldTrim())->toBeFalse()
        ->and($limit->trim()->shouldTrim())->toBeTrue()
        ->and($limit->trim(false)->shouldTrim())->toBeFalse();
});

it('sets and reports a max wait ceiling', function () {
    $limit = new Limit;

    expect($limit->hasMaxWait())->toBeFalse()
        ->and($limit->getMaxWait())->toBeNull()
        ->and($limit->exceedsMaxWait(10_000))->toBeFalse();

    $limit->maxWait(5_000);

    expect($limit->hasMaxWait())->toBeTrue()
        ->and($limit->getMaxWait())->toBe(5_000)
        ->and($limit->exceedsMaxWait(5_001))->toBeTrue()
        ->and($limit->exceedsMaxWait(5_000))->toBeFalse();
});

it('sets jitter and clamps negatives to zero', function () {
    $limit = new Limit;

    expect($limit->getJitter())->toBe(0)
        ->and($limit->jitter(50)->getJitter())->toBe(50)
        ->and($limit->jitter(-5)->getJitter())->toBe(0);
});

it('toggles the adaptive flag', function () {
    $limit = new Limit;

    expect($limit->isAdaptive())->toBeFalse()
        ->and($limit->adaptive()->isAdaptive())->toBeTrue()
        ->and($limit->adaptive(false)->isAdaptive())->toBeFalse();
});

it('checks whether attempt is under or above max attempts', function () {
    $limit = new Limit(maxAttempts: 5);

    expect($limit->isUnderMaxAttempts(4))
        ->toBeTrue()
        ->and($limit->isUnderMaxAttempts(6))
        ->toBeFalse()
        ->and($limit->isOverMaxAttempts(6))
        ->toBeTrue()
        ->and($limit->isOverMaxAttempts(4))
        ->toBeFalse();
});
