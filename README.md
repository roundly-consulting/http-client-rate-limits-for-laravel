<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/http-client-rate-limits-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=http-client-rate-limits-for-laravel">
    <img src="art/hero.png" alt="http-client-rate-limits for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

# HTTP Client Rate Limits for Laravel

Rate limit outgoing requests made with Laravel's HTTP client. Wrap any
`Illuminate\Http\Client` request with a small Guzzle middleware that throttles how often
requests go out — `perSecond`, `perMinute`, or `perHour` — and transparently waits when a
limit is reached, so you never blow past a third-party API's quota.

- Per-second / per-minute / per-hour limits with a fluent factory.
- Pluggable **Store** (where request timestamps are kept) and **Deferrer** (how the wait is
  performed). Ships an in-process `InMemoryStore` + a shared `RedisStore`, and a millisecond
  `SleepDeferrer`.
- Scope a limit to an "owner" (e.g. an IP or account) so independent callers don't share a
  budget.
- Swap defaults per request, or globally via config — no required configuration to get started.

## Requirements

- PHP `^8.4`
- Laravel `^12.0 | ^13.0`

## Installation

```bash
composer require roundly-consulting/http-client-rate-limits-for-laravel
```

The service provider is auto-discovered. Optionally publish the config file:

```bash
php artisan vendor:publish --tag="http-client-rate-limits-config"
```

## Configuration

The published file lives at `config/http-client-rate-limits.php`:

```php
return [
    // Default Store used by RateLimit::make()/perSecond()/perMinute()/perHour().
    // Must implement RoundlyConsulting\HttpClientRateLimits\Store\Store.
    'store' => env('HTTP_CLIENT_RATE_LIMITS_STORE', InMemoryStore::class),

    // Default Deferrer used to read "now" and to pause when a limit is hit.
    // Must implement RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer.
    'deferrer' => env('HTTP_CLIENT_RATE_LIMITS_DEFERRER', SleepDeferrer::class),

    // Redis connection name (from config/database.php) used when the store is the RedisStore.
    'redis_connection' => env('HTTP_CLIENT_RATE_LIMITS_REDIS_CONNECTION', 'default'),
];
```

| Key | Type | Default | Env | Purpose |
|---|---|---|---|---|
| `store` | `class-string<Store>` | `InMemoryStore::class` | `HTTP_CLIENT_RATE_LIMITS_STORE` | Default store for new rate limits. |
| `deferrer` | `class-string<Deferrer>` | `SleepDeferrer::class` | `HTTP_CLIENT_RATE_LIMITS_DEFERRER` | Default deferrer for new rate limits. |
| `redis_connection` | `string` | `'default'` | `HTTP_CLIENT_RATE_LIMITS_REDIS_CONNECTION` | Redis connection the `RedisStore` uses. |

A configured `store`/`deferrer` that does not implement the matching contract throws an
`InvalidArgumentException` when a rate limit is created.

## Usage

### Apply a rate limit to the HTTP client

`RateLimit` is a Guzzle middleware. Attach it with `Http::withMiddleware()`:

```php
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\HttpClientRateLimits\RateLimit;

$response = Http::withMiddleware(RateLimit::perSecond(5))
    ->get('https://api.example.com/things');
```

When the limit is reached, the middleware waits the exact time until the next request is
allowed, then proceeds — you simply get your response a little later.

### Available factories

```php
new RateLimit(Limiter $limiter);                 // build it yourself
RateLimit::make(Limit $limit);                   // from a Limit value object
RateLimit::perSecond(int $maxAttempts = 1);      // N requests per second
RateLimit::perMinute(int $maxAttempts = 1);      // N requests per minute
RateLimit::perHour(int $maxAttempts = 1);        // N requests per hour
```

### Scope a limit to an owner

Useful when a single quota is shared across servers/accounts and you want each owner tracked
separately (e.g. per outbound IP):

```php
$middleware = RateLimit::perHour(60)->by('203.0.113.10');

Http::withMiddleware($middleware)->get('https://api.example.com/orders');
```

### Inspect the limit without sending a request

`RateLimit` forwards calls to the underlying `Limit`/`Limiter`, so you can ask how long you'd
have to wait before the next call is allowed:

```php
$middleware = RateLimit::perHour(6);

$at    = $middleware->getDeferrer()->timestamp();
$delay = $middleware->delayUntilNextRequestInMs($at); // 0 = send now, else wait this many ms

$middleware->isOverMaxAttempts(7); // true
```

### Swap the store / deferrer per instance

```php
use RoundlyConsulting\HttpClientRateLimits\Store\RedisStore;

$middleware = RateLimit::perMinute(30);

$middleware->setStore(new RedisStore('cache')); // share limits across processes via Redis
$middleware->setDeferrer($myDeferrer);
```

### Change the defaults globally

In a service provider (or via the config file above):

```php
use RoundlyConsulting\HttpClientRateLimits\RateLimit;
use RoundlyConsulting\HttpClientRateLimits\Store\RedisStore;

RateLimit::use(
    defaultStore: new RedisStore('cache'),
    defaultDeferrer: new MyDeferrer(),
);
```

Every `RateLimit` created afterwards uses those defaults. Call `RateLimit::use()` with no
arguments to reset back to the config-driven defaults.

### Stores

- **`InMemoryStore`** (default) — keeps request timestamps in process memory. Great for a
  single worker/CLI run; not shared between processes.
- **`RedisStore`** — keeps timestamps in a sorted set so limits are shared across processes
  and servers. Pass the Redis connection name (defaults to `default`):

  ```php
  use RoundlyConsulting\HttpClientRateLimits\Store\RedisStore;

  $store = new RedisStore('default');
  ```

Write your own by implementing
`RoundlyConsulting\HttpClientRateLimits\Store\Store`.

### Deferrers

- **`SleepDeferrer`** (default) — reads the current time in milliseconds and pauses with
  `Illuminate\Support\Sleep` (fakeable in tests).

Write your own by implementing
`RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer` — for example to queue/delay the
request instead of sleeping.

## Testing

```bash
composer test
```

The Redis-backed tests run automatically when a Redis connection is reachable and skip
otherwise.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
