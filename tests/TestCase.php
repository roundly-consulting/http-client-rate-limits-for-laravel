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
}
