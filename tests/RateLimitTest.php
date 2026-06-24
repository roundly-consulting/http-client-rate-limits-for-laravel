<?php

declare(strict_types=1);

use Psr\Http\Message\RequestInterface;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\SleepDeferrer;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\Limiter;
use RoundlyConsulting\HttpClientRateLimits\RateLimit;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\HttpClientRateLimits\Store\RedisStore;
use RoundlyConsulting\HttpClientRateLimits\Tests\TestDeferrer;
use RoundlyConsulting\HttpClientRateLimits\Tests\TestStore;

it('creates new instance using make static method', function () {
    $instance = RateLimit::make(
        $limit = new Limit('testing', 5, 'hour'),
    );

    expect($instance)
        ->toBeInstanceOf(RateLimit::class)
        ->getLimiter()->toBeInstanceOf(Limiter::class)
        ->and($instance->getLimiter())
        ->getLimit()->toBe($limit)
        ->getStore()->toBeInstanceOf(InMemoryStore::class)
        ->getDeferrer()->toBeInstanceOf(SleepDeferrer::class);
});

it('configures default store and deferrer', function () {
    RateLimit::use(new TestStore, new TestDeferrer);

    $instance = RateLimit::make(
        new Limit('testing', 5, 'hour'),
    );

    expect($instance)
        ->getStore()->toBeInstanceOf(TestStore::class)
        ->getDeferrer()->toBeInstanceOf(TestDeferrer::class);

    RateLimit::use();
});

it('resolves default store and deferrer from config', function () {
    config()->set('http-client-rate-limits.store', TestStore::class);
    config()->set('http-client-rate-limits.deferrer', TestDeferrer::class);

    $instance = RateLimit::make(new Limit('testing', 5, 'hour'));

    expect($instance)
        ->getStore()->toBeInstanceOf(TestStore::class)
        ->getDeferrer()->toBeInstanceOf(TestDeferrer::class);
});

it('resolves a redis store with the configured connection from config', function () {
    config()->set('http-client-rate-limits.store', RedisStore::class);
    config()->set('http-client-rate-limits.redis_connection', 'cache');

    $instance = RateLimit::make(new Limit('testing', 5, 'hour'));

    expect($instance->getStore())->toBeInstanceOf(RedisStore::class);
});

it('falls back to in-memory store and sleep deferrer by default', function () {
    $instance = RateLimit::make(new Limit('testing', 5, 'hour'));

    expect($instance)
        ->getStore()->toBeInstanceOf(InMemoryStore::class)
        ->getDeferrer()->toBeInstanceOf(SleepDeferrer::class);
});

it('throws when the configured store does not implement the Store contract', function () {
    config()->set('http-client-rate-limits.store', stdClass::class);

    RateLimit::make(new Limit('testing', 5, 'hour'));
})->throws(InvalidArgumentException::class);

it('throws when the configured deferrer does not implement the Deferrer contract', function () {
    config()->set('http-client-rate-limits.deferrer', stdClass::class);

    RateLimit::make(new Limit('testing', 5, 'hour'));
})->throws(InvalidArgumentException::class);

it('creates new instance using static method with per second limits', function () {
    $instance = RateLimit::perSecond(5);

    expect($instance)
        ->toBeInstanceOf(RateLimit::class)
        ->and($instance->getLimiter()->getLimit())
        ->getMaxAttempts()->toBe(5)
        ->getTimespan()->toBe('second');
});

it('creates new instance using static method with per minute limits', function () {
    $instance = RateLimit::perMinute(8);

    expect($instance)
        ->toBeInstanceOf(RateLimit::class)
        ->and($instance->getLimiter()->getLimit())
        ->getMaxAttempts()->toBe(8)
        ->getTimespan()->toBe('minute');
});

it('creates new instance using static method with per hour limits', function () {
    $instance = RateLimit::perHour(4);

    expect($instance)
        ->toBeInstanceOf(RateLimit::class)
        ->and($instance->getLimiter()->getLimit())
        ->getMaxAttempts()->toBe(4)
        ->getTimespan()->toBe('hour');
});

it('forwards methods to underlying limit class or limiter class', function () {
    $instance = RateLimit::perMinute(6);

    expect($instance)
        ->getMaxAttempts()->toBe(6)
        ->getTimespan()->toBe('minute')
        ->by('john')->getKey()->toBe('john')
        ->and($instance)
        ->getStore()->toBeInstanceOf(InMemoryStore::class)
        ->getDeferrer()->toBeInstanceOf(SleepDeferrer::class);
});

it('throws exception when no method is found on RateLimit instance or underlying limit / limiter class', function () {
    $instance = RateLimit::perMinute(2);

    $instance->doesntExist();
})->throws(RuntimeException::class, 'Method [doesntExist] not found on RateLimit or Limiter class.');

it('return closure that works as guzzle request middleware and uses limiter', function () {
    $deferrer = new TestDeferrer;

    $instance = RateLimit::perMinute();
    $instance->setDeferrer($deferrer);

    $request = $this->mock(RequestInterface::class);

    $called = false;
    $usedRequest = null;
    $usedOptions = null;

    $handler = function ($request, $options) use (&$called, &$usedRequest, &$usedOptions) {
        $called = true;
        $usedRequest = $request;
        $usedOptions = $options;

        return 'OK';
    };

    $middleware = $instance($handler);
    $response = $middleware($request, ['My' => 'Options']);

    expect($response)->toBe('OK')
        ->and($usedRequest)->toBe($request)
        ->and($usedOptions)->toBe(['My' => 'Options'])
        ->and($deferrer->timestamp())->toBe(0)
        ->and($instance->getLimiter()->delayUntilNextRequestInMs(0))->toBe(60000);

    $middleware = $instance($handler);
    $response = $middleware($request, ['My' => 'Options']);

    expect($response)->toBe('OK')
        ->and($usedRequest)->toBe($request)
        ->and($usedOptions)->toBe(['My' => 'Options'])
        ->and($deferrer->timestamp())->toBe(60000);
});
