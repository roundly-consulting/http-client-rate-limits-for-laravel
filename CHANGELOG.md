# Changelog

All notable changes to `http-client-rate-limits-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

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
- Pluggable stores — in-memory, cache, Redis and database — and deferrers, including
  `RateLimits::releasingJob($job)`, which releases a queued job back onto the queue instead of
  blocking the worker.
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

### Fixed

- A `usingStore()` / `usingDeferrer()` copy of the manager no longer resolves its own
  config store: every copy shares the process store, so an in-memory limit built through a copy
  accumulates hits with the rest.
