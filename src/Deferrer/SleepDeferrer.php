<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Deferrer;

use Illuminate\Support\Sleep;

final class SleepDeferrer implements Deferrer
{
    public function timestamp(): int
    {
        return now()->getTimestampMs();
    }

    public function defer(int $ms): void
    {
        Sleep::usleep($ms * 1000);
    }
}
