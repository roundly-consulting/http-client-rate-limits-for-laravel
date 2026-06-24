<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;

/**
 * Parses the standard `Retry-After` header so consumers can honour server-sent
 * backoff (e.g. feed it into Laravel's own `->retry()` sleep callback). The
 * header is either delta-seconds or an HTTP-date (RFC 7231).
 */
final class RetryAfter
{
    /**
     * Number of seconds to wait per the `Retry-After` header, or null when the
     * header is absent or unparseable.
     */
    public static function seconds(Response|RequestException $source): ?int
    {
        $response = $source instanceof RequestException ? $source->response : $source;

        $header = $response->header('Retry-After');

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        try {
            $seconds = (int) ceil(Carbon::now()->diffInSeconds(Carbon::parse($header), false));
        } catch (InvalidFormatException) {
            return null;
        }

        return max($seconds, 0);
    }
}
