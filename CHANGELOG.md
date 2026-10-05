# Changelog

All notable changes to `http-client-rate-limits-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Changed

- Limiter profiles no longer share the `global` bucket: a profile without `by` is keyed by its
  own name, so each keeps its own budget and one API's `Retry-After` no longer stalls the rest.
  To keep profiles on one budget, give them the same `by` (`'by' => 'global'` restores the old
  sharing).
- `Http::rateLimit([...])` throws `InvalidLimitException` for an empty array or an entry that
  is not a `Limit`, a `RateLimit`, a profile name or an integer, instead of dropping it and
  running under a default 60-per-second `global` limit. Pass only those four; profile names
  and integers in the array now work as they do on their own.
- The package now requires `roundly-consulting/crypto-for-laravel` (`^1.0`); Composer installs
  it with the package. The `DatabaseStore` uses its `Digest` for over-long keys.

### Fixed

- `RateLimits::releasingJob()` together with `Http::retry()` released the job once per try, and
  the database queue added a new copy of the job for each release. The job is released once.
- On MySQL/MariaDB the `DatabaseStore` could let a compound limit, or an attempt inside a host
  transaction, past its budget: its reads saw an old REPEATABLE READ snapshot and missed hits
  other workers had just committed. Reads inside an attempt now lock and see the latest hits.
- `RateLimits::fake()` recorded nothing while `Event::fake()` was active, so `assertDeferred()`
  failed and `assertNothingDeferred()` passed although requests waited. The fake now records
  the limits it builds directly.
- The fake's clock ignored Carbon test time, so after `travel()` past a window it still
  deferred. It now follows test time, unless built with an explicit start.
- An adaptive limit around a callback that calls `->throw()` ignored the 429's `Retry-After`;
  it now records the backoff before rethrowing.
- A fractional `X-RateLimit-Reset` (`1790000030.250`) was ignored; it is now rounded up to the
  next second.
- The `InMemoryStore` never dropped idle owners or expired penalties, so a long-running worker
  keyed per user kept growing. It now sweeps them at most once a minute.
- A `by` key longer than about 248 characters overflowed the `DatabaseStore`'s 255-character
  `owner` columns: Postgres and strict MySQL threw on every request, and non-strict MySQL cut the
  key short so the limit never matched. Such keys are now stored as their SHA-256 digest, for
  hits and owner rows alike.

## 1.0.0 - 2026-10-03

Initial public release.

### Added

- Rate limiting for Laravel's HTTP client: a one-line `Http::rateLimit(...)` macro and a Guzzle
  `RateLimit` middleware that waits until the next request is allowed.
- Per-second, per-minute, per-hour and per-day limits (`RateLimits::perSecond()` … `perDay()`),
  scoped per owner with `by:` so independent callers keep separate budgets.
- Named limiter profiles defined once in config and used by name: `Http::rateLimit('github')`.
- Compound limits that enforce several windows at once (`->alongside()`,
  `RateLimits::compound()`), the strictest one winning.
- `->maxWait()` to fail fast with a typed exception instead of blocking, and `->jitter()` to
  spread retries.
- `->adaptive()` limiting that follows the server's `Retry-After` / `X-RateLimit-*` headers, plus
  `RateLimits::retryAfter()` for Laravel's `->retry()`.
- Pre-flight inspection with `remaining()`, `availableIn()` and `tooManyAttempts()`, and
  `->reset()` to clear a key's recorded hits.
- Pluggable stores — in-memory, cache, Redis and database — each checking a request against
  every window and recording it as one atomic step (`Store::attempt()`: a lock, a Lua script or a
  locking transaction), and deferrers, including `RateLimits::releasingJob($job)`, which releases
  a queued job back onto the queue instead of blocking the worker, with the
  `HandlesRateLimitRelease` job middleware to end that attempt cleanly.
- `database_connection` config key to give the `DatabaseStore` its own connection.
- `RequestDeferred`, `RequestAllowed` and `RateLimitReset` events for logging, metrics and alerts.
- `RateLimits::fake()` with `assertDeferred()`, `assertAllowed()`, `assertReset()` and their
  `assertNothing…()` twins for testing throttling without real sleeps. The fake is a
  `RateLimitManager`, so injected managers and `Http::rateLimit()` get it too.

### Changed

- The `RateLimits` facade (or an injected `RateLimitManager`) is the single entry point: the
  static `RateLimit::use()`, `make()`, `perSecond()`, `perMinute()`, `perHour()` and `perDay()`
  are removed — `RateLimit::use()` set process-global defaults that bypassed the manager and
  `RateLimits::fake()`. Configure defaults in `config/http-client-rate-limits.php` or per call
  site with `RateLimits::usingStore()` / `usingDeferrer()`.
- `RetryAfter` is internal; use `RateLimits::retryAfter()`.
- The `Store` contract gains `attempt()`, and `Deferrer::defer()` takes the limit key. The
  `DatabaseStore` migration creates a second table, `http_client_rate_limit_owners`, which holds
  penalties (the hits table loses `penalized_until`). `RedisStore` keys are hash-tagged by limit
  key.

### Fixed

- A `usingStore()` / `usingDeferrer()` copy of the manager no longer resolves its own
  config store: every copy shares the process store, so an in-memory limit built through a copy
  accumulates hits with the rest.
- Workers sharing a store could both take one freed slot: checking and recording were separate
  steps and the limiter never re-checked after waiting. Both are fixed — the store attempt is
  atomic and a wait is always followed by a fresh attempt.
- `RedisStore` counted hits recorded in the same millisecond once (the timestamp was also the
  sorted-set member); each hit now has a unique member.
- Jitter could shorten a wait below the time the window frees; it now only adds.
- `by()` / `by:` on a compound limit re-keyed only the primary window, and the macro re-keyed
  the caller's own `RateLimit`; every window is re-keyed now, on a copy.
- The wait for an over-full window was measured from its oldest hit, so the request still went
  out over the limit; it now waits for hit `[count - max]`.
- A `ReleaseDeferrer` release surfaced in the worker as a job exception (reported, counted
  toward `$maxExceptions`, failing the job while its released copy was still queued).
- A huge `Retry-After` / `X-RateLimit-Reset` crashed adaptive mode with a `TypeError`; server
  waits are capped at one day.
- `RateLimits::fake()` ignored `usingStore()`, `usingDeferrer()` and `releasingJob()`.
- `remaining()` ignored an adaptive penalty; it reports 0 while one is in force.
- A limit of 0 (or fewer) attempts let the first request through; it now throws
  `InvalidLimitException`.
- `JobReleasedException` always named the key `global`; it names the releasing limit's key.
- `events_enabled` read `off` / `no` from the environment as enabled.
- A limiter profile's `trim` / `adaptive` was cast with `(bool)`, so `off`/`no` switched it on.
  Both are read strictly now and throw on anything but a boolean spelling, naming the profile key.
- A limiter profile's junk `rate` became 1 and a junk `max_wait` / `jitter` was ignored; a
  non-string `by` became the global key. Numbers are read strictly now (an integer or an integer
  string, `max_wait` / `jitter` at least 0), `per` throws `InvalidTimespanException` naming the
  profile key, and a non-string `by` throws.
- A non-string `cache_store`, `cache_prefix`, `redis_connection` or `database_connection`
  silently used the default; it throws `InvalidConfigurationException` now.
- Blank means not set: a blank value (a host's `KEY=`, empty or whitespace only) reads exactly like
  an absent key and takes its default. That covers `store`, `deferrer` (a blank one threw
  `InvalidStoreException` / `InvalidDeferrerException`), the four store settings and every
  profile key — a blank profile `max_wait` no longer turns into a 0 ms fail-fast ceiling.
