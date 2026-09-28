<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer;
use RoundlyConsulting\HttpClientRateLimits\Events\RateLimitReset;
use RoundlyConsulting\HttpClientRateLimits\Events\RequestAllowed;
use RoundlyConsulting\HttpClientRateLimits\Events\RequestDeferred;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\RateLimit;
use RoundlyConsulting\HttpClientRateLimits\RateLimitManager;
use RoundlyConsulting\HttpClientRateLimits\Store\Store;
use RoundlyConsulting\HttpClientRateLimits\Testing\RateLimitsFake;

/**
 * @method static RateLimit make(Limit $limit)
 * @method static RateLimit profile(string $name)
 * @method static RateLimit compound(list<Limit|RateLimit> $limits)
 * @method static RateLimit perSecond(int $maxAttempts = 1)
 * @method static RateLimit perMinute(int $maxAttempts = 1)
 * @method static RateLimit perHour(int $maxAttempts = 1)
 * @method static RateLimit perDay(int $maxAttempts = 1)
 * @method static RateLimitManager usingStore(Store $store)
 * @method static RateLimitManager usingDeferrer(Deferrer $deferrer)
 * @method static RateLimitManager releasingJob(object $job)
 * @method static int|null retryAfter(\Illuminate\Http\Client\Response|\Illuminate\Http\Client\RequestException $response)
 * @method static Store store()
 * @method static Deferrer deferrer()
 *
 * @see RateLimitManager
 */
final class RateLimits extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RateLimitManager::class;
    }

    /**
     * Swap the manager for a recording fake that never sleeps, so tests can
     * assert on throttling without real waits or Redis. Injected managers get it too.
     */
    public static function fake(): RateLimitsFake
    {
        $app = self::getFacadeApplication();

        $fake = new RateLimitsFake($app->make('config'));

        // Capture the package's own events into the fake; force them on so the
        // assertions work regardless of the host's events_enabled setting.
        $app->make('config')->set('http-client-rate-limits.events_enabled', true);

        $events = $app->make('events');
        $events->listen(RequestDeferred::class, $fake->recordDeferred(...));
        $events->listen(RequestAllowed::class, $fake->recordAllowed(...));
        $events->listen(RateLimitReset::class, $fake->recordReset(...));

        self::swap($fake);

        return $fake;
    }
}
