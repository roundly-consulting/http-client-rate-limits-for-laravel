<?php

declare(strict_types=1);

/**
 * The config contract this package never had, pinned in both directions:
 *
 *  - forward — every key the code reads is shipped. This is shops #18, whose whole
 *    store-credit feature read `shops.payments.*` while the file shipped `payment.*`;
 *    330 tests stayed green because the suite set the same wrong key the code read.
 *  - reverse — every shipped leaf is read. A documented key nothing reads is dead config
 *    that lies to the host: media #27's `max_file_size` cap that never applied, alerts
 *    #24's thrice-documented `escalation` key. This package ships four env-backed knobs
 *    (`cache_store`, `cache_prefix`, `redis_connection`, `events_enabled`) that a host can
 *    only trust if something really reads them.
 */
it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../../config/http-client-rate-limits.php')->toSatisfyConfigContract(__DIR__.'/../../src', [
        // Deliberately NO `excludeFromReverse` for the provider. The testing README's own
        // example excludes the service provider on the grounds that "a render is not a
        // read" — but this provider's `contributesToAbout()` closure calls
        // `config('http-client-rate-limits.…')` for real for `store`, `deferrer`,
        // `limiters` and `events_enabled`, and `bindFromConfig()` does real reads too.
        // Excluding it would discard the only reader of several bound keys and weaken the
        // reverse direction for nothing.
        //
        // `extraReadPrefixes` here is NOT about a model seam (this package has none). It is
        // what makes the injected-Repository reads visible.
        //
        // RateLimitManager takes an `Illuminate\Contracts\Config\Repository` by constructor
        // and reads through `$this->config->get('http-client-rate-limits.…')`. The scraper
        // recognises the bare `config()` helper and `Config::get()`, but not a `->get()` on
        // an injected repository — so `cache_store` and `cache_prefix`, which are ONLY read
        // that way, scrape as unread and fail the reverse direction. They are not dead
        // config: RateLimitManager::resolveStore() genuinely wires both into the CacheStore
        // constructor, and tests/Store/CacheStoreTest.php exercises it.
        //
        // (`store`, `deferrer`, `limiters` and `redis_connection` are read through BOTH
        // forms — the manager's injected repository and a bare `config()` elsewhere — which
        // is why they stayed visible and only these two surfaced. That is worth knowing: the
        // gap is silent wherever a key happens to have a second, helper-style reader.)
        //
        // Counting literals under the prefix is exact here rather than over-eager: the only
        // other `http-client-rate-limits` literal in src/ is the cache-prefix DEFAULT value,
        // which carries no trailing dot and so does not match.
        'extraReadPrefixes' => ['http-client-rate-limits.'],
    ]);
});
