<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Enums;

use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidTimespanException;

enum Timespan: string
{
    case Second = 'second';
    case Minute = 'minute';
    case Hour = 'hour';
    case Day = 'day';

    /**
     * Resolve a Timespan from its string value, throwing a typed exception
     * instead of the native \ValueError on an unknown window.
     */
    public static function fromValue(string $value): self
    {
        return self::tryFrom($value) ?? throw InvalidTimespanException::for($value);
    }

    public function lengthInMs(): int
    {
        return match ($this) {
            self::Second => 1_000,
            self::Minute => 60_000,
            self::Hour => 3_600_000,
            self::Day => 86_400_000,
        };
    }
}
