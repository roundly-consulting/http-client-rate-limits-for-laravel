<?php

declare(strict_types=1);

use RoundlyConsulting\HttpClientRateLimits\Deferrer\SleepDeferrer;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;

return [

    /*
    |--------------------------------------------------------------------------
    | Named Limiter Profiles
    |--------------------------------------------------------------------------
    |
    | Reusable, named limits you can reference by string instead of rebuilding
    | them at every call site: Http::rateLimit('github')->get(...). Each profile
    | is an array with: "rate" (int), "per" (second|minute|hour|day), and the
    | optional "by", "trim" (bool), "max_wait" (ms), "jitter" (ms), and
    | "adaptive" (bool) keys. Referencing an undefined name throws
    | UnknownLimiterProfileException. Values are read strictly: a number that
    | isn't an integer ("five", "5.5"), a negative max_wait/jitter, a "per"
    | typo or a non-string "by" throws, naming the profile key. A blank value
    | (a host's KEY=) is not set and takes the key's default.
    |
    */

    'limiters' => [
        // 'github' => ['rate' => 5, 'per' => 'second', 'by' => null],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Store
    |--------------------------------------------------------------------------
    |
    | The Store implementation used to record request timestamps for every
    | RateLimit the manager builds (RateLimits::perMinute(), Http::rateLimit(), ...).
    | Ship with the in-process InMemoryStore, or — to share limits across
    | processes and servers — the CacheStore (any cache store the app already
    | runs), the RedisStore, or the DatabaseStore. Each checks and records a
    | request as one atomic step. Must implement the
    | RoundlyConsulting\HttpClientRateLimits\Store\Store contract. Unset or
    | blank (HTTP_CLIENT_RATE_LIMITS_STORE=) means the InMemoryStore.
    |
    */

    'store' => env('HTTP_CLIENT_RATE_LIMITS_STORE', InMemoryStore::class),

    /*
    |--------------------------------------------------------------------------
    | Cache Store Settings
    |--------------------------------------------------------------------------
    |
    | Used only when the store above is the CacheStore. "cache_store" is the
    | cache store name from config/cache.php (null = the default store) and
    | "cache_prefix" namespaces the cache keys the package writes. A blank
    | value is not set and takes the default; a non-string value throws.
    |
    */

    'cache_store' => env('HTTP_CLIENT_RATE_LIMITS_CACHE_STORE'),

    'cache_prefix' => env('HTTP_CLIENT_RATE_LIMITS_CACHE_PREFIX', 'http-client-rate-limits'),

    /*
    |--------------------------------------------------------------------------
    | Default Deferrer
    |--------------------------------------------------------------------------
    |
    | The Deferrer implementation used to read the current timestamp and pause
    | execution when a limit is reached. The default SleepDeferrer uses
    | Illuminate\Support\Sleep (millisecond precision). Must implement the
    | RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer contract. Unset
    | or blank means the SleepDeferrer.
    |
    */

    'deferrer' => env('HTTP_CLIENT_RATE_LIMITS_DEFERRER', SleepDeferrer::class),

    /*
    |--------------------------------------------------------------------------
    | Redis Connection
    |--------------------------------------------------------------------------
    |
    | The Redis connection name (from config/database.php) the RedisStore uses
    | when it is selected as the default store. Ignored for any other store.
    | A blank value is not set and takes "default"; a non-string one throws.
    |
    */

    'redis_connection' => env('HTTP_CLIENT_RATE_LIMITS_REDIS_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The database connection name (from config/database.php) the DatabaseStore
    | uses when it is selected as the default store; null = the default
    | connection. A dedicated connection keeps the store's short locking
    | transactions out of any transaction your code has open. Ignored for any
    | other store. Unset, "null" or blank (KEY=) means the default connection;
    | a non-string value throws.
    |
    */

    'database_connection' => env('HTTP_CLIENT_RATE_LIMITS_DATABASE_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | When enabled, the limiter dispatches RequestDeferred (a request was paused)
    | and RequestAllowed (a request was recorded) so you can log, meter, or alert
    | on throttling. Turn off for the lowest possible overhead.
    |
    */

    'events_enabled' => env('HTTP_CLIENT_RATE_LIMITS_EVENTS_ENABLED', true),

];
