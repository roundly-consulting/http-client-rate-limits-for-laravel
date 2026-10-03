<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Support;

/**
 * @internal Blank means not set. A host's `KEY=` arrives as `''`, which the toolkit's
 *           readers already treat exactly like an absent key; the optional settings
 *           read here (a store connection, a profile's `by` / `max_wait`) follow the same
 *           rule, so a blank value takes its documented default rather than throwing.
 */
final class ConfigValue
{
    /**
     * Whether a raw config value is set: not null, and not a blank string (empty or
     * whitespace only).
     */
    public static function isSet(mixed $value): bool
    {
        return $value !== null && ! (is_string($value) && trim($value) === '');
    }
}
