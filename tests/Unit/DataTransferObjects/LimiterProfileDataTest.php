<?php

declare(strict_types=1);

use RoundlyConsulting\HttpClientRateLimits\DataTransferObjects\LimiterProfileData;
use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidTimespanException;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

it('builds from a minimal config with defaults', function () {
    $data = LimiterProfileData::fromConfig([]);

    expect($data->rate)->toBe(1)
        ->and($data->per)->toBe(Timespan::Minute)
        ->and($data->by)->toBeNull()
        ->and($data->trim)->toBeFalse()
        ->and($data->maxWaitMs)->toBeNull()
        ->and($data->jitterMs)->toBe(0)
        ->and($data->adaptive)->toBeFalse();
});

it('builds from a full config array', function () {
    $data = LimiterProfileData::fromConfig([
        'rate' => 5,
        'per' => 'second',
        'by' => 'github',
        'trim' => true,
        'max_wait' => 2_000,
        'jitter' => 50,
        'adaptive' => true,
    ]);

    expect($data->rate)->toBe(5)
        ->and($data->per)->toBe(Timespan::Second)
        ->and($data->by)->toBe('github')
        ->and($data->trim)->toBeTrue()
        ->and($data->maxWaitMs)->toBe(2_000)
        ->and($data->jitterMs)->toBe(50)
        ->and($data->adaptive)->toBeTrue();
});

it('accepts a Timespan enum for the per key', function () {
    $data = LimiterProfileData::fromConfig(['rate' => 3, 'per' => Timespan::Hour]);

    expect($data->per)->toBe(Timespan::Hour);
});

it('converts to a configured Limit', function () {
    $limit = LimiterProfileData::fromConfig([
        'rate' => 5,
        'per' => 'second',
        'by' => 'github',
        'trim' => true,
        'max_wait' => 2_000,
        'jitter' => 50,
        'adaptive' => true,
    ])->toLimit();

    expect($limit->getMaxAttempts())->toBe(5)
        ->and($limit->getTimespan())->toBe('second')
        ->and($limit->getKey())->toBe('github')
        ->and($limit->shouldTrim())->toBeTrue()
        ->and($limit->getMaxWait())->toBe(2_000)
        ->and($limit->getJitter())->toBe(50)
        ->and($limit->isAdaptive())->toBeTrue();
});

// Changed deliberately (chat-review C-1): this pinned `global`, so every profile without a `by`
// shared one bucket and one API's Retry-After penalty stalled all the others.
it('defaults the limit key to the profile name without a by', function () {
    $limit = LimiterProfileData::fromConfig(['rate' => 2, 'per' => 'minute'], name: 'github')->toLimit();

    expect($limit->getKey())->toBe('github')
        ->and($limit->getMaxWait())->toBeNull()
        ->and($limit->getJitter())->toBe(0)
        ->and($limit->isAdaptive())->toBeFalse();
});

it('keys a profile on its by over its name, and a nameless one on global', function () {
    expect(LimiterProfileData::fromConfig(['by' => 'gh'], name: 'github')->toLimit()->getKey())->toBe('gh')
        ->and(LimiterProfileData::fromConfig([])->toLimit()->getKey())->toBe('global');
});

it('reads env-style trim and adaptive switches as booleans', function (string $value, bool $expected) {
    // `(bool) 'off'` is true: an env `off`/`no` used to switch both ON.
    $data = LimiterProfileData::fromConfig(['trim' => $value, 'adaptive' => $value]);

    expect($data->trim)->toBe($expected)
        ->and($data->adaptive)->toBe($expected);
})->with([
    'off' => ['off', false],
    'no' => ['no', false],
    '0' => ['0', false],
    'on' => ['on', true],
    'yes' => ['yes', true],
    '1' => ['1', true],
]);

it('refuses an unreadable profile switch, naming the profile key (strict config)', function (string $leaf) {
    config()->set('http-client-rate-limits.limiters.github', ['rate' => 5, $leaf => 'disabled']);

    expect(fn () => RateLimits::profile('github'))->toThrow(
        InvalidConfigurationException::class,
        "Configuration value [http-client-rate-limits.limiters.github.{$leaf}] must be a boolean",
    );
})->with(['trim', 'adaptive']);

it('reads canonical integer strings from an env-backed profile (strict config)', function () {
    $data = LimiterProfileData::fromConfig(['rate' => '5', 'max_wait' => ' 2000 ', 'jitter' => '50']);

    expect($data->rate)->toBe(5)
        ->and($data->maxWaitMs)->toBe(2_000)
        ->and($data->jitterMs)->toBe(50);
});

it('refuses a junk profile number instead of guessing one (strict config)', function (string $leaf, mixed $value) {
    config()->set('http-client-rate-limits.limiters.github', ['rate' => 5, $leaf => $value]);

    expect(fn () => RateLimits::profile('github'))->toThrow(
        InvalidConfigurationException::class,
        "Configuration value [http-client-rate-limits.limiters.github.{$leaf}] must be an integer",
    );
})->with([
    'rate five' => ['rate', 'five'],
    'rate 5.5' => ['rate', '5.5'],
    'max_wait soon' => ['max_wait', 'soon'],
    'max_wait 1e3' => ['max_wait', '1e3'],
    'max_wait bool' => ['max_wait', true],
    'jitter 50ms' => ['jitter', '50ms'],
    'jitter float' => ['jitter', 12.5],
]);

it('refuses a negative max_wait or jitter (strict config)', function (string $leaf) {
    config()->set('http-client-rate-limits.limiters.github', ['rate' => 5, $leaf => '-1']);

    expect(fn () => RateLimits::profile('github'))->toThrow(
        InvalidConfigurationException::class,
        "Configuration value [http-client-rate-limits.limiters.github.{$leaf}] must be at least 0, [-1] given.",
    );
})->with(['max_wait', 'jitter']);

it('refuses a typo in the profile window, naming the key (strict config)', function (mixed $per) {
    config()->set('http-client-rate-limits.limiters.github', ['rate' => 5, 'per' => $per]);

    expect(fn () => RateLimits::profile('github'))->toThrow(
        InvalidTimespanException::class,
        'Configuration value [http-client-rate-limits.limiters.github.per] must be one of [second, minute, hour, day]',
    );
})->with([
    'plural' => ['minutes'],
    'capitalised' => ['Minute'],
    'number' => [60],
    'bool' => [true],
]);

it('refuses a non-string profile key (strict config)', function (mixed $by) {
    config()->set('http-client-rate-limits.limiters.github', ['rate' => 5, 'by' => $by]);

    expect(fn () => RateLimits::profile('github'))->toThrow(
        InvalidConfigurationException::class,
        'Configuration value [http-client-rate-limits.limiters.github.by] must be a non-empty string',
    );
})->with([
    'int' => [42],
    'array' => [['tenant']],
]);

it('reads a blank profile value as not set, so every default applies (strict config)', function (string $blank) {
    // A host's `KEY=` arrives as '' — exactly like an omitted key, never a throw, a 0 or false.
    $data = LimiterProfileData::fromConfig([
        'rate' => $blank,
        'per' => $blank,
        'by' => $blank,
        'trim' => $blank,
        'max_wait' => $blank,
        'jitter' => $blank,
        'adaptive' => $blank,
    ], name: 'github');

    expect($data)->toEqual(LimiterProfileData::fromConfig([], name: 'github'))
        ->and($data->maxWaitMs)->toBeNull()
        // Changed deliberately (chat-review C-1): a blank `by` keys the profile on its name, not `global`.
        ->and($data->toLimit()->getKey())->toBe('github');
})->with(['empty env' => [''], 'whitespace' => ['  ']]);
