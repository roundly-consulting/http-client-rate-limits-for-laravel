<?php

declare(strict_types=1);

use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;

// No toReachEveryAction(): the package has no src/Actions — it is a stateless limiter
// toolkit whose manager builds middleware objects rather than running use-case actions.
it('pins the RateLimits facade to its manager and its fake', function () {
    expect(RateLimits::class)
        ->toDocumentItsRoot()
        ->toBeFakeable();
});
