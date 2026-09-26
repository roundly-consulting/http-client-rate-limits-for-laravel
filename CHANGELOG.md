# Changelog

All notable changes to `http-client-rate-limits-for-laravel` will be documented in this file.

## 1.0.0 - Unreleased

### Fixed

- `->adaptive()` now works when the limiter is Guzzle middleware (`Http::rateLimit()`,
  `Http::withMiddleware()`, `Http::pool()`, adaptive named profiles): the handler returns a
  promise of a PSR-7 response, which the limiter used to ignore, so `Retry-After` /
  `X-RateLimit-*` never took effect outside `handle(fn () => ...)`.
- Stacked or compound limits on the same key no longer count each request once per limit:
  hits are recorded per window under `{key}:{window}` (`Limit::storeKey()`), exactly once per
  window, so `perSecond(5)` + `perMinute(100)` allows 5 a second again and trimming the short
  window no longer wipes the long one.
- The default store is shared: every rate limit the manager builds uses one store per
  configuration (container singleton), so `Http::rateLimit()` calls accumulate into one budget
  instead of each starting from an empty `InMemoryStore`. The `InMemoryStore` is per-process
  only — use the cache, Redis or database store across workers. The `RateLimit::use()`
  partial-defaults path now resolves the configured store through the manager too, so
  `cache_store` / `cache_prefix` are honoured there.
- The `InMemoryStore` and `DatabaseStore` now drop hits older than one day (plus an hour's
  margin) on write, like the cache and Redis stores, so a shared or long-lived store stays
  bounded.
- An `X-RateLimit-Reset` epoch that has already passed (clock skew, a slow response) asks for
  no wait instead of being read as a delta of ~57 years.
- A `maxWait()` or `jitter()` on any window of a compound limit now applies whichever window is
  the bottleneck (tightest ceiling, widest jitter); the ceiling set on the primary window used
  to be skipped when an `alongside()` window forced the wait.
- `RequestDeferred::$hitsInWindow` reports the real number of requests in the window that forced
  the wait (`0` when an adaptive penalty alone did) instead of that window's `maxAttempts`.
