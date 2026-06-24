<?php

declare(strict_types=1);

use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidDeferrerException;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidStoreException;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidTimespanException;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\RateLimitException;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\UndefinedMethodException;

it('builds an invalid store exception describing the bad value', function () {
    $exception = InvalidStoreException::for(new stdClass);

    expect($exception)
        ->toBeInstanceOf(RateLimitException::class)
        ->and($exception->getMessage())
        ->toContain('must be a class implementing')
        ->toContain('got [stdClass]');
});

it('builds an invalid deferrer exception describing the bad value', function () {
    $exception = InvalidDeferrerException::for('nope');

    expect($exception)
        ->toBeInstanceOf(RateLimitException::class)
        ->and($exception->getMessage())->toContain('got [string]');
});

it('builds an invalid timespan exception naming the value', function () {
    $exception = InvalidTimespanException::for('week');

    expect($exception)
        ->toBeInstanceOf(RateLimitException::class)
        ->and($exception->getMessage())->toContain('Timespan [week] is not supported');
});

it('builds an undefined method exception naming the method', function () {
    $exception = UndefinedMethodException::for('foo');

    expect($exception)
        ->toBeInstanceOf(RateLimitException::class)
        ->and($exception->getMessage())->toContain('Method [foo] not found');
});
