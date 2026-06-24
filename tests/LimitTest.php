<?php

declare(strict_types=1);

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

it('returns timespan length in ms for specific timespan', function () {
    $limit = new Limit;

    $limit->perSecond(1);
    expect($limit->timespanLengthInMs())->toBe(1_000);

    $limit->perMinute(1);
    expect($limit->timespanLengthInMs())->toBe(60_000);

    $limit->perHour(1);
    expect($limit->timespanLengthInMs())->toBe(3_600_000);
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
