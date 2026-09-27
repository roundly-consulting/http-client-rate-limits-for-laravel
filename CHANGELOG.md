# Changelog

All notable changes to `http-client-rate-limits-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

Initial public release.

### Added

- Rate limiting for Laravel's HTTP client: a one-line `Http::rateLimit(...)` macro and a Guzzle
  `RateLimit` middleware that waits until the next request is allowed.
- Per-second, per-minute, per-hour and per-day limits (`RateLimit::perSecond()` … `perDay()`),
  scoped per owner with `by:` so independent callers keep separate budgets.
- Named limiter profiles defined once in config and used by name: `Http::rateLimit('github')`.
- Compound limits that enforce several windows at once (`->alongside()`,
  `RateLimits::compound()`), the strictest one winning.
- `->maxWait()` to fail fast with a typed exception instead of blocking, and `->jitter()` to
  spread retries.
- `->adaptive()` limiting that follows the server's `Retry-After` / `X-RateLimit-*` headers, plus
  a `RetryAfter::seconds()` helper for Laravel's `->retry()`.
- Pre-flight inspection with `remaining()`, `availableIn()` and `tooManyAttempts()`.
- Pluggable stores — in-memory, cache, Redis and database — and deferrers, including a
  `ReleaseDeferrer` that releases a queued job back onto the queue instead of blocking the worker.
- `RequestDeferred` and `RequestAllowed` events for logging, metrics and alerts.
- `RateLimits::fake()` with `assertDeferred()`, `assertAllowed()` and `assertNothingDeferred()`
  for testing throttling without real sleeps.
