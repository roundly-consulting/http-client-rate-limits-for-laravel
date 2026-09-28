<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;

/**
 * Parses the standard `Retry-After` header: delta-seconds or an HTTP-date (RFC 7231).
 * Hosts call `RateLimits::retryAfter()`; the adaptive limiter uses this directly.
 *
 * @internal
 */
final class RetryAfter
{
    /**
     * The longest wait honoured (one day, the largest window), so a hostile or broken
     * header can neither overflow the millisecond arithmetic nor stall a worker for years.
     */
    public const MAX_SECONDS = 86_400;

    /**
     * Number of seconds to wait per the `Retry-After` header (capped at MAX_SECONDS), or
     * null when the header is absent or unparseable.
     */
    public static function seconds(Response|RequestException $source): ?int
    {
        $response = $source instanceof RequestException ? $source->response : $source;

        $header = $response->header('Retry-After');

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            // The cast saturates at PHP_INT_MAX; the cap then brings it into range.
            return self::cap((int) $header);
        }

        try {
            $seconds = Carbon::now()->diffInSeconds(Carbon::parse($header), false);
        } catch (InvalidFormatException) {
            return null;
        }

        return self::cap((int) ceil(min($seconds, self::MAX_SECONDS)));
    }

    /**
     * Clamp a wait to [0, MAX_SECONDS].
     */
    public static function cap(int $seconds): int
    {
        return min(max($seconds, 0), self::MAX_SECONDS);
    }
}
