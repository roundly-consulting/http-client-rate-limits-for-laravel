<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\RecordingDeferrer;

it('records defers and advances its clock instead of sleeping', function () {
    $deferrer = new RecordingDeferrer(1_000);

    expect($deferrer->timestamp())->toBe(1_000)
        ->and($deferrer->deferCount())->toBe(0);

    $deferrer->defer(500, 'global');
    $deferrer->defer(250, 'global');

    expect($deferrer->defers())->toBe([500, 250])
        ->and($deferrer->deferCount())->toBe(2)
        ->and($deferrer->timestamp())->toBe(1_750);
});

it('seeds its clock from now when constructed without a timestamp', function () {
    $deferrer = new RecordingDeferrer;

    expect($deferrer->timestamp())->toBeGreaterThan(0);
});

it('follows Carbon test time when built without a start, never running behind its defers', function () {
    Carbon::setTestNow(Carbon::createFromTimestampMs(1_700_000_000_000));
    $deferrer = new RecordingDeferrer;

    expect($deferrer->timestamp())->toBe(1_700_000_000_000);

    $deferrer->defer(500, 'global');

    expect($deferrer->timestamp())->toBe(1_700_000_000_500);

    Carbon::setTestNow(Carbon::createFromTimestampMs(1_700_000_061_000));

    expect($deferrer->timestamp())->toBe(1_700_000_061_000);

    $deferrer->defer(250, 'global');

    expect($deferrer->timestamp())->toBe(1_700_000_061_250)
        ->and($deferrer->defers())->toBe([500, 250]);

    Carbon::setTestNow();
});

it('keeps an explicit start deterministic, whatever the test time', function () {
    Carbon::setTestNow(Carbon::createFromTimestampMs(1_700_000_000_000));
    $deferrer = new RecordingDeferrer(1_000);

    Carbon::setTestNow(Carbon::createFromTimestampMs(1_700_000_061_000));
    $deferrer->defer(500, 'global');

    expect($deferrer->timestamp())->toBe(1_500);

    Carbon::setTestNow();
});
