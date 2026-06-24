<?php

declare(strict_types=1);

use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidTimespanException;

it('returns the length in milliseconds for every case', function () {
    expect(Timespan::Second->lengthInMs())->toBe(1_000)
        ->and(Timespan::Minute->lengthInMs())->toBe(60_000)
        ->and(Timespan::Hour->lengthInMs())->toBe(3_600_000)
        ->and(Timespan::Day->lengthInMs())->toBe(86_400_000);
});

it('resolves a timespan from a valid string value', function () {
    expect(Timespan::fromValue('second'))->toBe(Timespan::Second)
        ->and(Timespan::fromValue('minute'))->toBe(Timespan::Minute)
        ->and(Timespan::fromValue('hour'))->toBe(Timespan::Hour)
        ->and(Timespan::fromValue('day'))->toBe(Timespan::Day);
});

it('throws a typed exception for an unknown string value', function () {
    Timespan::fromValue('fortnight');
})->throws(InvalidTimespanException::class, 'Timespan [fortnight] is not supported');
