<?php

declare(strict_types=1);

use RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\RateLimitException;
use RoundlyConsulting\HttpClientRateLimits\Store\Store;

it('will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

it('extends the base exception for every package exception')
    ->expect('RoundlyConsulting\HttpClientRateLimits\Exceptions')
    ->toExtend(RateLimitException::class);

it('implements the store contract for every store')
    ->expect('RoundlyConsulting\HttpClientRateLimits\Store')
    ->classes()
    ->toImplement(Store::class);

it('implements the deferrer contract for every deferrer')
    ->expect('RoundlyConsulting\HttpClientRateLimits\Deferrer')
    ->classes()
    ->toImplement(Deferrer::class);

it('backs the timespan enum with a string')
    ->expect('RoundlyConsulting\HttpClientRateLimits\Enums\Timespan')
    ->toBeStringBackedEnum();

it('never uses the DB facade')
    ->expect('RoundlyConsulting\HttpClientRateLimits')
    ->not->toUse('Illuminate\Support\Facades\DB');
