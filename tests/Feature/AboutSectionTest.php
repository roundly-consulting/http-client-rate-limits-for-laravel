<?php

declare(strict_types=1);

/**
 * The secret-safe `about` capture (A).
 *
 * Purchases #13 is the bug this exists for: the fleet's most credential-heavy `about`
 * section was guarded by negative assertions against `app(Kernel::class)->output()`, which
 * returns `''` — every "does not leak" check was vacuous.
 *
 * What this package must not render is not an API key: it is the host's *topology*. A
 * limiter profile is keyed by the third-party service the host talks to and by whatever the
 * host limits `by` (routinely a tenant id, an account id, or an API token used as the
 * bucket key), and `redis_connection` / `cache_store` name the host's own infrastructure.
 * The section reports profiles as a COUNT and everything else as a class base name — so the
 * count is the thing that must render, and the names must not.
 */
it('renders the section without leaking the limiter profiles or infrastructure it names', function (): void {
    config()->set('http-client-rate-limits.limiters', [
        // A profile name is a vendor the host integrates with...
        'acme-internal-billing' => ['rate' => 5, 'per' => 'second', 'by' => 'tenant-9f2c-secret-bucket'],
        'stripe' => ['rate' => 100, 'per' => 'minute'],
    ]);
    config()->set('http-client-rate-limits.redis_connection', 'acme-ratelimit-cluster');
    config()->set('http-client-rate-limits.cache_store', 'acme-internal-memcached');
    config()->set('http-client-rate-limits.cache_prefix', 'acme-prod-9f2c');

    expect('http-client-rate-limits')->toLeakNoSecrets(
        secrets: [
            'acme-internal-billing',
            'stripe',
            // The bucket key is the closest thing this package holds to a credential.
            'tenant-9f2c-secret-bucket',
            // The host's infrastructure names.
            'acme-ratelimit-cluster',
            'acme-internal-memcached',
            'acme-prod-9f2c',
        ],
        mustRender: [
            'Store',
            'Deferrer',
            'InMemoryStore',
            'SleepDeferrer',
            // The count itself must render — the positive proof that the profiles line is
            // reporting rather than silently empty, and the reason the names above being
            // absent means something.
            '2',
            'ENABLED',
        ],
    );
});

/**
 * The switches render as switches. Kept separate: it is a rendering pin, not a leak pin,
 * and it needs the opposite config to the case above.
 */
it('reports disabled events to the about command', function (): void {
    config()->set('http-client-rate-limits.events_enabled', false);

    expect('http-client-rate-limits')->toLeakNoSecrets(
        secrets: ['ENABLED'],
        mustRender: ['OFF', 'Limiter profiles'],
    );
});

/**
 * A store that is not a class name still renders a label rather than a raw value or a
 * crash — the fallback path.
 */
it('falls back to a default label when the store is not a class name', function (): void {
    config()->set('http-client-rate-limits.store', null);

    expect('http-client-rate-limits')->toLeakNoSecrets(
        secrets: ['InMemoryStore'],
        mustRender: ['default', 'Store'],
    );
});
