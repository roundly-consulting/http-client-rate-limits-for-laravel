<?php

declare(strict_types=1);

use RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;
use RoundlyConsulting\HttpClientRateLimits\RateLimitManager;
use RoundlyConsulting\HttpClientRateLimits\Store\Store;
use RoundlyConsulting\HttpClientRateLimits\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Swap in a manager on this store and deferrer, so the facade, injected managers and
 * `Http::rateLimit()` all build limits on them for the rest of the test.
 */
function rateLimitsUsing(Store $store, Deferrer $deferrer): RateLimitManager
{
    $manager = app(RateLimitManager::class)->usingStore($store)->usingDeferrer($deferrer);

    RateLimits::swap($manager);

    return $manager;
}
