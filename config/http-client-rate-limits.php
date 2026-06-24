<?php

declare(strict_types=1);

use RoundlyConsulting\HttpClientRateLimits\Deferrer\SleepDeferrer;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Store
    |--------------------------------------------------------------------------
    |
    | The Store implementation used to record request timestamps for every
    | RateLimit created with RateLimit::make()/perSecond()/perMinute()/perHour().
    | Ship with the in-process InMemoryStore, or switch to the RedisStore to
    | share limits across processes and servers. Must implement the
    | RoundlyConsulting\HttpClientRateLimits\Store\Store contract.
    |
    */

    'store' => env('HTTP_CLIENT_RATE_LIMITS_STORE', InMemoryStore::class),

    /*
    |--------------------------------------------------------------------------
    | Default Deferrer
    |--------------------------------------------------------------------------
    |
    | The Deferrer implementation used to read the current timestamp and pause
    | execution when a limit is reached. The default SleepDeferrer uses
    | Illuminate\Support\Sleep (millisecond precision). Must implement the
    | RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer contract.
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
    |
    */

    'redis_connection' => env('HTTP_CLIENT_RATE_LIMITS_REDIS_CONNECTION', 'default'),

];
