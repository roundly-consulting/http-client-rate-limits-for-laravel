<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\RateLimit;
use RoundlyConsulting\HttpClientRateLimits\RateLimitManager;
use RoundlyConsulting\HttpClientRateLimits\Store\Store;

/**
 * @method static RateLimit make(Limit $limit)
 * @method static RateLimit perSecond(int $maxAttempts = 1)
 * @method static RateLimit perMinute(int $maxAttempts = 1)
 * @method static RateLimit perHour(int $maxAttempts = 1)
 * @method static RateLimit perDay(int $maxAttempts = 1)
 * @method static RateLimitManager usingStore(Store $store)
 * @method static RateLimitManager usingDeferrer(Deferrer $deferrer)
 *
 * @see RateLimitManager
 */
final class RateLimits extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RateLimitManager::class;
    }
}
