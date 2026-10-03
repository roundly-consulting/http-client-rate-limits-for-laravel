<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/http-client-rate-limits-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=http-client-rate-limits-for-laravel">
    <img src="art/hero.png" alt="HTTP Client Rate Limits for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/http-client-rate-limits-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/http-client-rate-limits-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/http-client-rate-limits-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/http-client-rate-limits-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/http-client-rate-limits-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/http-client-rate-limits-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=http-client-rate-limits-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

# HTTP Client Rate Limits for Laravel

Rate limit outgoing requests made with Laravel's HTTP client. Wrap any
`Illuminate\Http\Client` request with a small Guzzle middleware that throttles how often
requests go out — `perSecond`, `perMinute`, or `perHour` — and transparently waits when a
limit is reached, so you never blow past a third-party API's quota.

- Per-second / per-minute / per-hour / per-day limits with a fluent factory.
- A one-line `Http::rateLimit(...)` macro and a `RateLimits` facade for a discoverable,
  IDE-autocompleted calling convention.
- **Named limiter profiles** — define a limit once in config and reference it by name:
  `Http::rateLimit('github')`.
- **Compound limits** — enforce several windows at once (e.g. 5/sec **and** 100/min); the
  strictest wins.
- **Max-wait cap** — `->maxWait(ms)` fails fast with a typed exception instead of blocking
  past a ceiling.
- **Response-header adaptive limiting** — `->adaptive()` reads `Retry-After` /
  `X-RateLimit-*` and self-tunes the store to the server's own budget.
- **Jitter / spread** — `->jitter(ms)` adds up to that much random extra wait to avoid
  thundering-herd alignment — never less than the real wait (the randomness source is
  injectable for deterministic tests).
- **Pre-flight inspection** — `remaining()`, `availableIn()`, `tooManyAttempts()` from the
  fluent surface, and `reset()` to clear a key's recorded hits.
- **Queue-aware deferrer** — `RateLimits::releasingJob($job)` releases a queued job back onto
  the queue (with the computed delay) instead of blocking the worker; the
  `HandlesRateLimitRelease` job middleware ends the attempt cleanly.
- **Testing fake** — `RateLimits::fake()` plus `assertDeferred()`, `assertAllowed()`,
  `assertReset()` and their `assertNothing…()` twins to test throttling without real sleeps or
  Redis.
- Pluggable **Store** (where request timestamps are kept) and **Deferrer** (how the wait is
  performed). Ships an in-process `InMemoryStore`, a `CacheStore` (shared via any cache the
  app already runs — no Redis required), a `RedisStore`, a `DatabaseStore` (for apps with no
  Redis), and a millisecond `SleepDeferrer`. Each store checks a request against every window
  and records it as **one atomic step** (a lock, a Lua script or a transaction — see
  [Stores](#stores)), and the limiter re-checks after every wait, so workers sharing a store
  never both take the same free slot.
- Scope a limit to an "owner" (e.g. an IP or account) so independent callers don't share a
  budget — every window of it, compound ones included.
- Dispatches `RequestDeferred` / `RequestAllowed` / `RateLimitReset` events so you can log,
  meter, or alert on throttling.
- `RateLimits::retryAfter()` to honour a server's `Retry-After` header alongside Laravel's own
  `->retry()`.
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

Only if you use the `DatabaseStore`, publish and run its migration (it creates the
`http_client_rate_limits` hits table and the `http_client_rate_limit_owners` table the store
locks and keeps adaptive penalties in):

```bash
php artisan vendor:publish --tag="http-client-rate-limits-migrations"
php artisan migrate
```

The migration is **not** loaded automatically — `php artisan migrate` only creates the
tables once you have published it into your app's
`database/migrations`. Publishing again is idempotent: it overwrites the file it already
placed instead of adding a second copy.

## Configuration

The published file lives at `config/http-client-rate-limits.php`:

```php
return [
    // Named limiter profiles referenced by string: Http::rateLimit('github').
    // Each is ['rate' => int, 'per' => second|minute|hour|day] plus optional
    // 'by', 'trim', 'max_wait' (ms), 'jitter' (ms), 'adaptive' (bool).
    'limiters' => [
        // 'github' => ['rate' => 5, 'per' => 'second', 'by' => null],
    ],

    // Default Store for every limit the manager builds (RateLimits::…, Http::rateLimit()).
    // Must implement RoundlyConsulting\HttpClientRateLimits\Store\Store.
    'store' => env('HTTP_CLIENT_RATE_LIMITS_STORE', InMemoryStore::class),

    // Default Deferrer used to read "now" and to pause when a limit is hit.
    // Must implement RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer.
    'deferrer' => env('HTTP_CLIENT_RATE_LIMITS_DEFERRER', SleepDeferrer::class),

    // Cache store name (from config/cache.php) and key prefix used by the CacheStore.
    'cache_store' => env('HTTP_CLIENT_RATE_LIMITS_CACHE_STORE'),
    'cache_prefix' => env('HTTP_CLIENT_RATE_LIMITS_CACHE_PREFIX', 'http-client-rate-limits'),

    // Redis connection name (from config/database.php) used when the store is the RedisStore.
    'redis_connection' => env('HTTP_CLIENT_RATE_LIMITS_REDIS_CONNECTION', 'default'),

    // Database connection name used when the store is the DatabaseStore (null = default).
    'database_connection' => env('HTTP_CLIENT_RATE_LIMITS_DATABASE_CONNECTION'),

    // Dispatch RequestDeferred / RequestAllowed / RateLimitReset events when the limiter runs.
    'events_enabled' => env('HTTP_CLIENT_RATE_LIMITS_EVENTS_ENABLED', true),
];
```

| Key | Type | Default | Env | Purpose |
|---|---|---|---|---|
| `limiters` | `array<string, array>` | `[]` | — | Named limiter profiles referenced by string. |
| `store` | `class-string<Store>` | `InMemoryStore::class` | `HTTP_CLIENT_RATE_LIMITS_STORE` | Default store for new rate limits (unset or blank = `InMemoryStore`). |
| `deferrer` | `class-string<Deferrer>` | `SleepDeferrer::class` | `HTTP_CLIENT_RATE_LIMITS_DEFERRER` | Default deferrer for new rate limits (unset or blank = `SleepDeferrer`). |
| `cache_store` | `?string` | `null` | `HTTP_CLIENT_RATE_LIMITS_CACHE_STORE` | Cache store name used by `CacheStore` (`null`/unset/blank = default). |
| `cache_prefix` | `string` | `'http-client-rate-limits'` | `HTTP_CLIENT_RATE_LIMITS_CACHE_PREFIX` | Key prefix used by `CacheStore`. |
| `redis_connection` | `string` | `'default'` | `HTTP_CLIENT_RATE_LIMITS_REDIS_CONNECTION` | Redis connection the `RedisStore` uses. |
| `database_connection` | `?string` | `null` | `HTTP_CLIENT_RATE_LIMITS_DATABASE_CONNECTION` | Database connection the `DatabaseStore` uses (`null`/unset/blank = default). |
| `events_enabled` | `bool` | `true` | `HTTP_CLIENT_RATE_LIMITS_EVENTS_ENABLED` | Dispatch throttling events. Accepts `true`/`false`, `1`/`0`, `on`/`off`, `yes`/`no`; anything else throws `InvalidConfigurationException`. |

A configured `store`/`deferrer` that does not implement the matching contract throws a typed
`InvalidStoreException` / `InvalidDeferrerException` (both extend `RateLimitException`) when a
rate limit is created. A limit must allow at least one request per window: a `rate` / max
attempts below 1 throws `InvalidLimitException`. The four store settings (`cache_store`,
`cache_prefix`, `redis_connection`, `database_connection`) are read strictly when the matching
store is resolved: a non-string value throws `InvalidConfigurationException` naming the key.
**Blank means not set:** an absent key, `null` and a blank value (a host's `KEY=`, empty or
whitespace only) all use the default, for every key above.

## Usage

### Apply a rate limit to the HTTP client (one line)

The package adds a `rateLimit()` macro to Laravel's HTTP client — the quickest way to throttle
outgoing calls:

```php
use Illuminate\Support\Facades\Http;

// Integer shorthand = N requests per minute.
$response = Http::rateLimit(30)->get('https://api.example.com/orders');
```

When the limit is reached, the middleware waits the exact time until the next request is
allowed, checks again, then proceeds — you simply get your response a little later. (If
another worker on a shared store took that slot while this one waited, it waits again rather
than sending over the limit.)

Pass a `RateLimit` (built by the `RateLimits` facade) or a `Limit` to pick a different window,
and `by:` to scope the budget to an owner:

```php
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;

Http::rateLimit(RateLimits::perSecond(5))->get('https://api.example.com/things');
Http::rateLimit(RateLimits::perDay(10_000))->get('https://api.example.com/report');

// Each owner gets its own budget (e.g. per account or outbound IP).
Http::rateLimit(30, by: 'acct-1')->get('https://api.example.com/orders');
```

### The `RateLimit` middleware directly

`RateLimit` is a Guzzle middleware, so you can also attach it with `Http::withMiddleware()`:

```php
$response = Http::withMiddleware(RateLimits::perSecond(5))
    ->get('https://api.example.com/things');
```

### The `RateLimits` facade

The facade is the one entry point for building limits. It resolves the container-bound
`RateLimitManager`, so the configured store/deferrer — or a `RateLimits::fake()` — apply to
every limit, including the ones `Http::rateLimit()` builds:

```php
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;

RateLimits::make(Limit $limit);                  // from a Limit value object
RateLimits::perSecond(int $maxAttempts = 1);     // N requests per second
RateLimits::perMinute(int $maxAttempts = 1);     // N requests per minute
RateLimits::perHour(int $maxAttempts = 1);       // N requests per hour
RateLimits::perDay(int $maxAttempts = 1);        // N requests per day
RateLimits::profile(string $name);               // a named profile from config
RateLimits::compound(array $limits);             // several windows at once
RateLimits::usingStore(Store $store);            // a copy of the manager on this store
RateLimits::usingDeferrer(Deferrer $deferrer);   // … or with this deferrer
RateLimits::releasingJob(object $job);           // … releasing a queued job instead of sleeping
RateLimits::retryAfter(Response|RequestException $response); // ?int seconds from Retry-After
RateLimits::store();                             // the shared default store
RateLimits::deferrer();                          // the default deferrer
```

Each returned `RateLimit` is fluent: `->by(...)`, `->alongside(...)`, `->maxWait(...)`,
`->jitter(...)`, `->adaptive()`, `->remaining()`, `->availableIn()`, `->tooManyAttempts()`,
`->reset()`. `by()` scopes every window the limit enforces; `alongside()` and `compound()` take
copies of the limits you hand them, so re-keying the result never changes those.

**Without the facade**, inject the manager — the same API and the same singleton:

```php
use RoundlyConsulting\HttpClientRateLimits\RateLimitManager;

public function __construct(private RateLimitManager $rateLimits) {}

Http::withMiddleware($this->rateLimits->perSecond(5)->by('acct-1'))->get($url);
```

The package has no action classes: a limit is a middleware object the manager builds, not a
use case. `new RateLimit(new Limiter($limit, $store, $deferrer))` builds one by hand when you
need full control.

### Named limiter profiles

Define reusable limits once in `config/http-client-rate-limits.php` and reference them by
name from anywhere:

```php
// config/http-client-rate-limits.php
'limiters' => [
    'github' => ['rate' => 5, 'per' => 'second'],
    'billing' => ['rate' => 100, 'per' => 'minute', 'by' => 'tenant-1', 'jitter' => 50],
],
```

```php
Http::rateLimit('github')->get('https://api.github.com/user');
```

Referencing a name that isn't defined throws `UnknownLimiterProfileException`. Each profile
array accepts `rate`, `per`, and the optional `by`, `trim`, `max_wait`, `jitter`, and
`adaptive` keys, each read strictly and never guessed at:

- `rate`, `max_wait` and `jitter` take an integer or an integer string (`'5'`); `'five'`,
  `'5.5'` or `'1e3'` throws `InvalidConfigurationException`, and so does a negative
  `max_wait` / `jitter`. A `rate` below 1 throws `InvalidLimitException`.
- `per` takes `second`, `minute`, `hour`, `day` (exact) or a `Timespan`; a typo such as
  `minutes` throws `InvalidTimespanException`.
- `by` takes a string; a non-string throws `InvalidConfigurationException`.
- `trim` and `adaptive` take `true`/`false`, `1`/`0`, `on`/`off` or `yes`/`no`; anything else
  throws `InvalidConfigurationException`.

Every error names the full key, e.g. `http-client-rate-limits.limiters.github.adaptive`. A key
that is not set — omitted, `null` or blank (`''`, whitespace) — uses its default (`rate` 1,
`per` minute, no `by`, no `max_wait`, `jitter` 0, `trim` and `adaptive` off).

### Compound limits (several windows at once)

Pass an array of limits to enforce them all on one request; the limiter defers to the
strictest and records the call once in every window when it is allowed:

```php
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;

Http::rateLimit([RateLimits::perSecond(5), RateLimits::perMinute(100)])
    ->get('https://api.example.com/things');

// Or fluently, alongside the primary window:
$middleware = RateLimits::perSecond(5)->alongside(RateLimits::perMinute(100));

// Or by stacking the macro:
Http::rateLimit(RateLimits::perSecond(5))->rateLimit(RateLimits::perMinute(100));

// `by:` scopes every window — here both acct-1:second and acct-1:minute.
Http::rateLimit([RateLimits::perSecond(5), RateLimits::perMinute(100)], by: 'acct-1')
    ->get('https://api.example.com/things');
```

Each window keeps its own count in the store — under `{key}:{window}`, e.g. `global:second`
and `global:minute` (see `Limit::storeKey()`) — so a request counts exactly once per window
however the limits are combined, and trimming the short window never erases the long one.
Limits that share both key and window share one budget, wherever they are built. A `maxWait()`
or `jitter()` set on any window of a compound limit applies to the whole wait (the tightest
ceiling and the widest jitter win), whichever window turns out to be the bottleneck.

A compound limit (an array, `compound()` or `alongside()`) checks and records all its windows
in one atomic store step. Stacked macros are two separate limiters: each is atomic on its own
windows, but the first records its hit before the second decides whether to wait — prefer the
compound form when that matters. `Http::rateLimit($limit, by: '...')` re-keys a **copy**, so a
`RateLimit` or `Limit` you pass in (and reuse elsewhere) keeps its own key.

### Fail fast with a max-wait cap

When you'd rather error than block for too long, cap the wait. A wait above the ceiling —
counting every round of it, if the limiter has to wait again after losing a slot to another
worker — throws `RateLimitExceededException` (extends `RateLimitException`) instead of
sleeping:

```php
Http::rateLimit(RateLimits::perHour(10)->maxWait(5_000)) // milliseconds
    ->get('https://api.example.com/report');
```

### Jitter / spread

Add randomised jitter to defers so many workers don't all wake at the same instant:

```php
Http::rateLimit(RateLimits::perMinute(30)->jitter(50)) // waits 0–50ms longer than needed
    ->get('https://api.example.com/orders');
```

Jitter only ever **adds** to a wait: a request never goes out before its window has room.

The randomness source is the injectable `Randomizer` contract — swap it in a `Limiter` for
deterministic tests.

### Adaptive limiting (honour the server's own budget)

Opt in with `->adaptive()` and the limiter reads `Retry-After` and `X-RateLimit-Remaining` /
`X-RateLimit-Reset` from each response, recording a penalty in the store so the next request
waits exactly as long as the server asked:

```php
Http::rateLimit(RateLimits::perSecond(20)->adaptive())
    ->get('https://api.example.com/things');
```

It works the same through `Http::rateLimit()`, `Http::withMiddleware()`, `Http::pool()`, an
adaptive named profile (`'adaptive' => true`), and `$rateLimit->handle(fn () => Http::get(...))`.
`X-RateLimit-Reset` may be a delta in seconds or an epoch timestamp; an epoch that has already
passed asks for no wait. A server wait is honoured up to **one day**: a larger `Retry-After` /
`X-RateLimit-Reset` (hostile or broken) is capped there rather than stalling the worker or
overflowing.

### Pre-flight inspection

Ask the limiter about the current state before sending — without recording a hit:

```php
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;

$limit = RateLimits::perMinute(30)->by('acct-1');

$limit->remaining();        // requests still allowed in the window (0 during a server penalty)
$limit->availableIn();      // ms until the next request is allowed (0 = now)
$limit->tooManyAttempts();  // bool — is the window exhausted right now?
```

These describe the primary window (plus any adaptive penalty on it) and record nothing.

### Reset a limit

Clear the hits a key has recorded — after a plan upgrade, or between two phases of a batch —
so the next request goes straight through:

```php
RateLimits::perMinute(60)->by('stripe')->reset();

// A compound limit clears every window it enforces.
RateLimits::perSecond(5)->by('stripe')->alongside(RateLimits::perMinute(100)->by('stripe'))->reset();
```

A reset clears the windows the limit enforces (`stripe:minute` above), not other windows on
the same key, and it does not lift a penalty an adaptive limit recorded from the server's own
`Retry-After` — the server asked for that wait. It fires `RateLimitReset`.

### Scope a limit to an owner

Useful when a single quota is shared across servers/accounts and you want each owner tracked
separately (e.g. per outbound IP). `by()` scopes every window the limit enforces:

```php
$middleware = RateLimits::perHour(60)->by('203.0.113.10');

Http::withMiddleware($middleware)->get('https://api.example.com/orders');
```

### Inspect the limit without sending a request

`RateLimit` forwards calls to the underlying `Limit`/`Limiter`, so you can ask how long you'd
have to wait before the next call is allowed:

```php
$middleware = RateLimits::perHour(6);

$at    = $middleware->getDeferrer()->timestamp();
$delay = $middleware->delayUntilNextRequestInMs($at); // 0 = send now, else wait this many ms

$middleware->isOverMaxAttempts(7); // true
```

### Swap the store / deferrer per instance

```php
use RoundlyConsulting\HttpClientRateLimits\Store\RedisStore;

$middleware = RateLimits::perMinute(30);

$middleware->setStore(new RedisStore('cache')); // share limits across processes via Redis
$middleware->setDeferrer($myDeferrer);
```

### Change the defaults

Set `store` / `deferrer` in the config file: every limit the manager builds uses them. For one
call site, `RateLimits::usingStore()` / `usingDeferrer()` return a configured **copy** of the
manager, so the shared singleton is never mutated:

```php
use RoundlyConsulting\HttpClientRateLimits\Store\RedisStore;

$limits = RateLimits::usingStore(new RedisStore('cache'))->usingDeferrer(new MyDeferrer);

$limits->perMinute(30);
```

A copy keeps sharing the manager's config-resolved store, so hits still accumulate in one
budget per process. Under `RateLimits::fake()` these overrides are honoured too.

### Stores

Every store checks a request against all of a limit's windows (and any adaptive penalty) and
records it as **one atomic step** — `Store::attempt()` — so two workers can never both take the
last free slot. How each one makes that step atomic:

- **`InMemoryStore`** (default) — keeps request timestamps in process memory. One instance is
  shared by every rate limit the app builds, so separate `Http::rateLimit()` calls accumulate
  into the same budget within a process. It is **per-process only**: each queue worker, PHP-FPM
  child or server keeps its own count, so with several workers use the `CacheStore`,
  `RedisStore` or `DatabaseStore` instead. Atomic because nothing outside the process sees it.
- **`CacheStore`** — shares limits across processes using whatever cache the app already runs
  (file, database, memcached, array, …) — no Redis required. The check-and-record runs under
  the cache's atomic lock (one per window, taken in a fixed order) when the cache store is a
  lock provider — every built-in Laravel cache store is; a custom store without locks is
  best-effort. A lock is held for at most `lockSeconds` (default 5), which is also how long a
  writer waits for it before throwing Laravel's `LockTimeoutException`. Configure it with the
  `cache_store`/`cache_prefix` config keys, or instantiate directly:

  ```php
  use RoundlyConsulting\HttpClientRateLimits\Store\CacheStore;

  $store = new CacheStore(store: 'redis', prefix: 'http-client-rate-limits');
  ```

  A `file` cache (and its locks) is local to one server; share the budget across servers with
  a `database`, `redis` or `memcached` cache.

- **`RedisStore`** — keeps timestamps in sorted sets (each hit a unique member, so hits in the
  same millisecond all count), shared across processes and servers. The whole check-and-record
  is **one Lua script**, which Redis runs without interleaving any other command. Keys are
  hash-tagged by limit key (`http-client-rate-limits:{acct-1}:second`), so on Redis Cluster a
  limit's windows and penalty share a slot; a compound limit whose windows use *different* keys
  needs a single-node Redis. Pass the Redis connection name (defaults to `default`):

  ```php
  use RoundlyConsulting\HttpClientRateLimits\Store\RedisStore;

  $store = new RedisStore('default');
  ```

- **`DatabaseStore`** — shares limits through two database tables (Eloquent, never the `DB`
  facade) for apps that run only a file/database cache and have no Redis. Each attempt is a
  short transaction that first writes the limit key's row in `http_client_rate_limit_owners` —
  a row lock on MySQL/MariaDB and Postgres, the write lock on SQLite — so a second worker's
  attempt on the same key waits until the first has checked **and** recorded. Select it with
  `'store' => DatabaseStore::class` and publish/run its migration (see Installation):

  ```php
  use RoundlyConsulting\HttpClientRateLimits\Store\DatabaseStore;

  $store = new DatabaseStore;                     // the default connection
  $store = new DatabaseStore(connection: 'limits'); // or `database_connection` in config
  ```

  Give it its own connection (`database_connection`) when rate-limited calls can run inside a
  transaction of yours: on the shared connection the store's transaction nests in yours, so
  its lock is held — and its hit stays invisible to other workers — until yours commits.

Every built-in store self-trims hits older than the largest supported window (one day, plus
an hour's margin) so long-lived keys — and the `DatabaseStore` tables — stay bounded. Every
store also records server-imposed penalties for adaptive limiting via `penalizeUntil()` /
`penalizedUntil()`. Write your own by implementing
`RoundlyConsulting\HttpClientRateLimits\Store\Store`; its `attempt()` is "take your lock, ask
`RoundlyConsulting\HttpClientRateLimits\Support\Windows::evaluate()`, record if allowed".

### Deferrers

- **`SleepDeferrer`** (default) — reads the current time in milliseconds and pauses with
  `Illuminate\Support\Sleep` (fakeable in tests).
- **`ReleaseDeferrer`** — for use inside a queued job: instead of blocking the worker, it
  releases the job back onto the queue with the computed delay (rounded up to whole seconds)
  and throws `JobReleasedException` (carrying the limit `key` and the `job`) to unwind the
  current attempt without sending the request. Opt in with `RateLimits::releasingJob()`, and
  give the job the `HandlesRateLimitRelease` middleware, which ends that attempt as a normal
  return — so the worker doesn't report it, fire `JobExceptionOccurred`, or count it toward
  the job's `$maxExceptions`:

  ```php
  use Illuminate\Bus\Queueable;
  use Illuminate\Contracts\Queue\ShouldQueue;
  use Illuminate\Foundation\Bus\Dispatchable;
  use Illuminate\Queue\InteractsWithQueue;
  use Illuminate\Support\Facades\Http;
  use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;
  use RoundlyConsulting\HttpClientRateLimits\Jobs\Middleware\HandlesRateLimitRelease;

  final class SyncThings implements ShouldQueue
  {
      use Dispatchable, InteractsWithQueue, Queueable;

      // Every release counts as an attempt: allow enough (or define retryUntil()).
      public int $tries = 10;

      public function middleware(): array
      {
          return [new HandlesRateLimitRelease];
      }

      public function handle(): void
      {
          $rateLimit = RateLimits::releasingJob($this)->perSecond(5);

          Http::withMiddleware($rateLimit)->get('https://api.example.com/things');
      }
  }
  ```

  As with any job Laravel releases, each release uses up one of the job's `$tries`, so give it
  enough of them. Without the middleware, catch the exception yourself — the job is already
  back on the queue:

  ```php
  use RoundlyConsulting\HttpClientRateLimits\Exceptions\JobReleasedException;

  try {
      Http::withMiddleware(RateLimits::releasingJob($this)->perSecond(5))->get($url);
  } catch (JobReleasedException) {
      return;
  }
  ```

Write your own by implementing
`RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer` — `timestamp(): int` (ms) and
`defer(int $ms, string $key): void`. After a defer the limiter checks the store again; a
deferrer whose clock did not move on (a simulated wait) is taken at its word.

### Events

When `events_enabled` is on (the default), the limiter dispatches:

- **`RequestDeferred`** — `string $key`, `int $delayMs`, `int $hitsInWindow`, `Timespan $timespan` —
  right before a request is paused because the budget is exhausted (again, if the request
  loses the slot to another worker while it waits). The key, count and window describe the
  window that forced the wait (for a compound limit, the strictest one); `hitsInWindow` is the
  real number of requests recorded in it, `0` when an adaptive server penalty alone caused the
  wait on an empty window.
- **`RequestAllowed`** — `string $key`, `int $hitsInWindow`, `Timespan $timespan` — after a
  request is recorded and allowed through.
- **`RateLimitReset`** — `string $key` — after `->reset()` cleared a limit's recorded hits.

Listen for them to log, chart, or alert on throttling:

```php
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\HttpClientRateLimits\Events\RequestDeferred;

Event::listen(function (RequestDeferred $event): void {
    logger()->warning('Throttled outbound request', [
        'key' => $event->key,
        'delay_ms' => $event->delayMs,
        'window' => $event->timespan->value,
    ]);
});
```

Set `events_enabled` to `false` for the lowest possible overhead.

### Reacting to 429 / Retry-After

This package *paces* outgoing requests proactively. To also react to a server's own signals,
use `->adaptive()` (above) or combine the middleware with Laravel's own `->retry()` and
`RateLimits::retryAfter()` to honour a `429 Too Many Requests` / `Retry-After` response:

```php
Http::rateLimit(RateLimits::perMinute(60))
    ->retry(3, throw: false, sleepMilliseconds: fn ($attempt, $exception) =>
        (RateLimits::retryAfter($exception) ?? $attempt) * 1000)
    ->get('https://api.example.com/things');
```

`RateLimits::retryAfter()` accepts a `Response` or a `RequestException`, parses both the
delta-seconds and HTTP-date forms of the header, caps the result at one day (86 400 s), and
returns `null` when it's absent or unparseable.

### Testing your own code

Swap the manager for a recording fake so your suite can assert on throttling without real
sleeps or Redis:

```php
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;

$fake = RateLimits::fake();

Http::rateLimit(1, by: 'acct-1')->get('https://api.example.com/one');
Http::rateLimit(1, by: 'acct-1')->get('https://api.example.com/two');

$fake->assertDeferred('acct-1');   // a request for this key was throttled
$fake->assertAllowed();            // at least one request went through
// $fake->assertNothingDeferred(); // would fail here

RateLimits::perMinute(60)->by('stripe')->reset();
$fake->assertReset('stripe');
```

`fake()` returns a `RateLimitsFake` — a `RateLimitManager` subtype, so an injected manager and
`Http::rateLimit()` get it too — exposing `assertDeferred(?string $key)` /
`assertNothingDeferred()`, `assertAllowed(?string $key)` / `assertNothingAllowed()`,
`assertReset(?string $key)` / `assertNothingReset()`, `deferredCount()` / `allowedCount()`, and
the shared `store()` / `deferrer()` for finer-grained assertions. The store records hits per window, so read them
back with the limit's store key: `$fake->store()->hits('acct-1:minute')`.

`RateLimits::usingStore()`, `usingDeferrer()` and `releasingJob()` still take effect under the
fake: they return a manager on your override that keeps the fake's recording store or
deferrer for the other half — so a released job really is released, and its events are still
recorded by the fake.

## Integrates with

- **[enums-for-laravel](https://github.com/roundly-consulting/enums-for-laravel)** — the
  `Timespan` enum uses the shared `RoundlyConsulting\Enums\Helpers` trait, so it exposes the
  full enum toolkit alongside its domain methods (`fromValue()`, `lengthInMs()`):

  ```php
  use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;

  Timespan::values();          // ['second', 'minute', 'hour', 'day']
  Timespan::labels();          // ['Second', 'Minute', 'Hour', 'Day']
  Timespan::options();         // list of {value, label, name} option DTOs
  Timespan::toOptions();       // value => label map for <select> inputs
  Timespan::validationRule();  // 'in:second,minute,hour,day'

  Timespan::Hour->label();     // 'Hour'
  Timespan::fromName('Hour');  // Timespan::Hour
  ```

- **[package-toolkit-for-laravel](https://github.com/roundly-consulting/package-toolkit-for-laravel)** —
  bootstraps the service provider (config merge/publish, migration publishing) and adds a
  `php artisan about` section reporting the active store, deferrer, limiter-profile count, and
  whether events are enabled:

  ```bash
  php artisan about --only=http-client-rate-limits
  ```

## Testing

```bash
composer test
```

The `RedisStore` cases run twice: against a real Redis when one is reachable, and through a Lua
harness (`tests/Support/LuaRedisConnection.php`) that executes the store's actual Lua script —
and every other command — in a real Lua interpreter against a `redis.call()` with Redis's
semantics, for machines with a `lua` binary but no redis-server. Each variant skips when its
backend is missing.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for what has changed recently.

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=http-client-rate-limits-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=http-client-rate-limits-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
