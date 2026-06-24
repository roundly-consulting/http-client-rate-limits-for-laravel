<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Exceptions;

use RoundlyConsulting\HttpClientRateLimits\Deferrer\Deferrer;

final class InvalidDeferrerException extends RateLimitException
{
    public static function for(mixed $value): self
    {
        return new self(sprintf(
            'Configured [http-client-rate-limits.deferrer] must be a class implementing [%s], got [%s].',
            Deferrer::class,
            get_debug_type($value),
        ));
    }
}
