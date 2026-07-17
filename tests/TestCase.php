<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\HttpClientRateLimits\HttpClientRateLimitsServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    /**
     * Every provider this package hard-requires, in registration order. A host
     * auto-discovers these; the suite must list them or the test environment is a fiction.
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function packageProviders(): array
    {
        return [HttpClientRateLimitsServiceProvider::class];
    }

    /**
     * The single hits migration, named by provider class (never by filename). The package
     * publishes rather than auto-loads it, so the suite has to run it explicitly.
     *
     * @return list<class-string<ServiceProvider>|string>
     */
    protected function migrationSources(): array
    {
        return [HttpClientRateLimitsServiceProvider::class];
    }
}
