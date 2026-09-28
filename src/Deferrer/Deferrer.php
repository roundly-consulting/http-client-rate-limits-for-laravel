<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Deferrer;

interface Deferrer
{
    /**
     * The current time in milliseconds — the clock every window is measured on.
     */
    public function timestamp(): int;

    /**
     * Wait `$ms` milliseconds before the request for limit `$key` may go. The limiter then
     * re-checks the store; a deferrer whose clock did not move on by `$ms` (a simulated
     * wait) is taken at its word, as if the time had passed.
     */
    public function defer(int $ms, string $key): void;
}
