<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use RoundlyConsulting\HttpClientRateLimits\HttpClientRateLimitsServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [HttpClientRateLimitsServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
    }

    /**
     * The package publishes its migrations rather than auto-loading them,
     * so the suite has to run them explicitly.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
