<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use RoundlyConsulting\HttpClientRateLimits\RetryAfter;

function retryAfterResponse(array $headers): Response
{
    return new Response(new PsrResponse(429, $headers));
}

afterEach(function () {
    Carbon::setTestNow();
});

it('parses an integer delta-seconds header', function () {
    expect(RetryAfter::seconds(retryAfterResponse(['Retry-After' => '30'])))->toBe(30);
});

it('parses an http-date header relative to now', function () {
    Carbon::setTestNow('2026-06-24 12:00:00');

    $response = retryAfterResponse(['Retry-After' => 'Wed, 24 Jun 2026 12:00:45 GMT']);

    expect(RetryAfter::seconds($response))->toBe(45);
});

it('returns null when the header is absent', function () {
    expect(RetryAfter::seconds(retryAfterResponse([])))->toBeNull();
});

it('returns null for a malformed header', function () {
    expect(RetryAfter::seconds(retryAfterResponse(['Retry-After' => 'not-a-date'])))->toBeNull();
});

it('clamps a past http-date to zero seconds', function () {
    Carbon::setTestNow('2026-06-24 12:00:00');

    $response = retryAfterResponse(['Retry-After' => 'Wed, 24 Jun 2026 11:59:00 GMT']);

    expect(RetryAfter::seconds($response))->toBe(0);
});

it('reads the header from a request exception', function () {
    $exception = new RequestException(retryAfterResponse(['Retry-After' => '12']));

    expect(RetryAfter::seconds($exception))->toBe(12);
});

it('caps an absurd delta at one day', function () {
    $response = new Response(new PsrResponse(429, ['Retry-After' => '99999999999999999999']));

    expect(RetryAfter::seconds($response))->toBe(RetryAfter::MAX_SECONDS)->toBe(86_400)
        ->and(RetryAfter::seconds(new Response(new PsrResponse(429, ['Retry-After' => '86401']))))->toBe(86_400)
        ->and(RetryAfter::seconds(new Response(new PsrResponse(429, ['Retry-After' => '86400']))))->toBe(86_400);
});

it('caps a far-future http-date at one day', function () {
    $response = new Response(new PsrResponse(429, ['Retry-After' => 'Fri, 31 Dec 9999 23:59:59 GMT']));

    expect(RetryAfter::seconds($response))->toBe(86_400);
});
