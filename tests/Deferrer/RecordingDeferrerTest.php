<?php

declare(strict_types=1);

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
