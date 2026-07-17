<?php

declare(strict_types=1);

use RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\RateLimitException;
use RoundlyConsulting\HttpClientRateLimits\Jitter\RandomRandomizer;
use RoundlyConsulting\HttpClientRateLimits\RateLimitManager;
use RoundlyConsulting\HttpClientRateLimits\Store\Store;
use RoundlyConsulting\Testing\Arch\ArchPresets;

/**
 * The presets replace the generic debugging-functions rule this file used to hand-roll.
 * The four bespoke contract rules below have no preset equivalent and are KEPT — they are
 * this package's own architecture (every store implements Store, every deferrer implements
 * Deferrer, every exception extends the base, the enum is string-backed), not a generic
 * rule the presets happen to cover.
 */
ArchPresets::strictTypes('RoundlyConsulting\HttpClientRateLimits');

/**
 * Two exemptions, both real extension points:
 *
 *  - RateLimitManager, which the shipped `Testing\RateLimitsFake` extends — `RateLimits::fake()`
 *    swaps the container binding for the subclass, so finalising it would break the
 *    package's own documented testing surface;
 *  - RateLimitException, the base every package exception extends so a host can catch them
 *    uniformly (pinned by the bespoke rule below).
 *
 * Everything else is closed. Adopting this preset found EIGHT classes left accidentally
 * open — the four stores, the deferrer, Limit, Limiter and RateLimit. None had a subclass
 * anywhere in src/ or tests/, and none is an extension point by design: a host adds a store
 * by IMPLEMENTING the Store contract that `http-client-rate-limits.store` binds, never by
 * extending a shipped one. They are final as of this row.
 *
 * The list goes through the `$ignoring` PARAMETER rather than Pest's fluent `->ignoring()`,
 * which buys two checks the fluent form cannot give:
 *
 *  - it is ROT-CHECKED. `::class` resolves to a string at compile time, so a rename would
 *    otherwise leave a green exemption silencing nothing while the ban quietly applies to a
 *    class everyone believes is exempt;
 *  - it recovers the SHADOW. Pest matches exemptions by string PREFIX, not class identity
 *    (pest-plugin-arch Blueprint.php:103), so an exemption also silences every class whose
 *    FQCN starts with it. Through the parameter, `finalByDefault` re-checks those by
 *    reflection. Measured here: this list shadows nothing — `RateLimitExceededException`
 *    diverges from `RateLimitException` at `Exce|eded`/`Exce|ption`, so it is policed
 *    normally — but the guard now stands if a `RateLimitManagerFoo` is ever added.
 */
ArchPresets::finalByDefault('RoundlyConsulting\HttpClientRateLimits', [
    RateLimitManager::class,
    RateLimitException::class,
]);

/**
 * No swappable models: `http-client-rate-limits.store` and `.deferrer` bind driver
 * implementations behind contracts, not Eloquent models a host subclasses, so
 * `swappableModelsAreNotFinal` has nothing to map and `modelsResolveThroughSeam` is
 * structurally inert here (no `*_model` key, and the package's one model — RateLimitHit —
 * is not configurable). Both are skipped with cause rather than registered vacuously.
 *
 * This package hands out rate-limit keys, not secrets, and does no cryptography — the ban
 * is a standing guard against a limiter key being hand-rolled from `random_bytes`/`hash`
 * here rather than taken from crypto-for-laravel.
 *
 * ONE exemption, and it is a use of randomness rather than a re-implementation of a
 * primitive: `RandomRandomizer` calls `random_int` to spread jitter so that N clients
 * coming off the same limit do not retry in lockstep. That is a thundering-herd control,
 * not a security boundary — nothing here derives a key, signs, or compares a secret. The
 * ban exists to stop a package growing its own crypto instead of depending on
 * crypto-for-laravel; requiring crypto-for-laravel to pick a random millisecond offset
 * would be a runtime dependency bought for nothing.
 *
 * The exemption is one class wide, not namespace wide, and that class exists precisely
 * because the randomness is already isolated behind the `Randomizer` contract (tests inject
 * a deterministic one) — which is the shape the ban wants anyway. Any second use of a
 * primitive elsewhere in the package still goes red.
 *
 * Through the `$ignoring` parameter, so it is rot-checked: if `RandomRandomizer` is ever
 * renamed or folded into another class, this fails as stale rather than silently exempting
 * nothing and re-banning `random_int` where the jitter actually lives. This is the second
 * of the two exemption lists in this file — each pin is registered under its own preset's
 * description, which is what lets them coexist.
 */
ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\HttpClientRateLimits', [
    RandomRandomizer::class,
]);

/**
 * The Dependency Policy as a test. No `alsoAllow`: this package's `require` ships only
 * php/illuminate/roundly, and the workflow installs test tooling with `--dev`, so nothing
 * legitimately lands in `require` that this must forgive. If it goes red, the graph is
 * wrong — never widen the allow-list to quiet it.
 */
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../composer.json');

ArchPresets::noDebuggingLeftovers();

it('extends the base exception for every package exception')
    ->expect('RoundlyConsulting\HttpClientRateLimits\Exceptions')
    ->toExtend(RateLimitException::class);

it('implements the store contract for every store')
    ->expect('RoundlyConsulting\HttpClientRateLimits\Store')
    ->classes()
    ->toImplement(Store::class);

it('implements the deferrer contract for every deferrer')
    ->expect('RoundlyConsulting\HttpClientRateLimits\Deferrer')
    ->classes()
    ->toImplement(Deferrer::class);

it('backs the timespan enum with a string')
    ->expect('RoundlyConsulting\HttpClientRateLimits\Enums\Timespan')
    ->toBeStringBackedEnum();

/**
 * Bespoke and kept: the package reaches the database through Eloquent on the RateLimitHit
 * model only, never the DB facade — so the DatabaseStore stays driver-portable and the
 * `never uses the DB facade` guarantee holds. This is also why this package ships no
 * driver-divergent SQL and needs no mysql leg.
 */
it('never uses the DB facade')
    ->expect('RoundlyConsulting\HttpClientRateLimits')
    ->not->toUse('Illuminate\Support\Facades\DB');
