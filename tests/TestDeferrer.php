<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Tests;

use RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer;

class TestDeferrer implements Deferrer
{
    public function __construct(protected int $timestamp = 0) {}

    public function timestamp(): int
    {
        return $this->timestamp;
    }

    public function defer(int $ms): void
    {
        $this->timestamp += $ms;
    }
}
