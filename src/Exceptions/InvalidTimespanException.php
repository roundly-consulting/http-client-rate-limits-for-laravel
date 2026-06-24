<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Exceptions;

final class InvalidTimespanException extends RateLimitException
{
    public static function for(string $value): self
    {
        return new self(sprintf(
            'Timespan [%s] is not supported. Use one of: second, minute, hour, day.',
            $value,
        ));
    }
}
