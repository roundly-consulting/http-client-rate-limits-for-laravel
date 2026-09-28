<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\SleepDeferrer;

it('returns current timestamp with milliseconds', function () {
    $deferrer = new SleepDeferrer;

    Carbon::setTestNow('2023-04-11 14:05:01');

    expect($deferrer->timestamp())->toBe(1681221901000);

    Carbon::setTestNow();
});

it('defer using sleep in ms', function () {
    $deferrer = new SleepDeferrer;

    Sleep::fake();

    $deferrer->defer(500, 'global');

    Sleep::assertSleptTimes(1);

    Sleep::assertSequence([
        Sleep::for(500)->milliseconds(),
    ]);
});
