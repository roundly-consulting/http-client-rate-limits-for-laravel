<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Deferrer;

interface Deferrer
{
    public function timestamp(): int;

    public function defer(int $ms): void;
}
