<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Exceptions;

final class UndefinedMethodException extends RateLimitException
{
    public static function for(string $method): self
    {
        return new self(sprintf(
            'Method [%s] not found on RateLimit or Limiter class.',
            $method,
        ));
    }
}
