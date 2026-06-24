<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Exceptions;

use RoundlyConsulting\HttpClientRateLimits\Store\Store;

final class InvalidStoreException extends RateLimitException
{
    public static function for(mixed $value): self
    {
        return new self(sprintf(
            'Configured [http-client-rate-limits.store] must be a class implementing [%s], got [%s].',
            Store::class,
            get_debug_type($value),
        ));
    }
}
