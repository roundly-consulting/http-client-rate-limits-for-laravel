<?php

declare(strict_types=1);

use Psr\Http\Message\RequestInterface;
use RoundlyConsulting\HttpClientRateLimits\Deferrer\SleepDeferrer;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidDeferrerException;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidStoreException;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\UndefinedMethodException;
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
})->throws(InvalidStoreException::class);

it('throws when the configured deferrer does not implement the Deferrer contract', function () {
    config()->set('http-client-rate-limits.deferrer', stdClass::class);

    RateLimit::make(new Limit('testing', 5, 'hour'));
})->throws(InvalidDeferrerException::class);

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

it('creates new instance using static method with per day limits', function () {
    $instance = RateLimit::perDay(100);

    expect($instance)
        ->toBeInstanceOf(RateLimit::class)
        ->and($instance->getLimiter()->getLimit())
        ->getMaxAttempts()->toBe(100)
        ->getTimespan()->toBe('day');
});

it('exposes explicit delegating methods that keep chaining on RateLimit', function () {
    $instance = RateLimit::perMinute(6);

    expect($instance->by('john'))
        ->toBeInstanceOf(RateLimit::class)
        ->getKey()->toBe('john')
        ->and($instance->getMaxAttempts())->toBe(6)
        ->and($instance->getTimespan())->toBe('minute')
        ->and($instance->isOverMaxAttempts(7))->toBeTrue()
        ->and($instance->isUnderMaxAttempts(2))->toBeTrue()
        ->and($instance->setStore(new TestStore))->toBeInstanceOf(RateLimit::class)
        ->and($instance->getStore())->toBeInstanceOf(TestStore::class)
        ->and($instance->setDeferrer(new TestDeferrer))->toBeInstanceOf(RateLimit::class)
        ->and($instance->getDeferrer())->toBeInstanceOf(TestDeferrer::class)
        ->and($instance->delayUntilNextRequestInMs(0))->toBe(0);
});

it('runs the limiter through the handle method', function () {
    $instance = RateLimit::perMinute(2)->setStore(new TestStore)->setDeferrer(new TestDeferrer);

    $executed = false;

    $instance->handle(function () use (&$executed) {
        $executed = true;
    });

    expect($executed)->toBeTrue();
});

it('throws typed exception when no method is found on RateLimit instance or underlying limit / limiter class', function () {
    $instance = RateLimit::perMinute(2);

    $instance->doesntExist();
})->throws(UndefinedMethodException::class, 'Method [doesntExist] not found on RateLimit or Limiter class.');

it('resolves remaining defaults statically when only one default is set', function () {
    RateLimit::use(defaultStore: new TestStore);

    $instance = RateLimit::perMinute(5);

    expect($instance->getStore())->toBeInstanceOf(TestStore::class)
        ->and($instance->getDeferrer())->toBeInstanceOf(SleepDeferrer::class);

    RateLimit::use();
});

it('resolves the store statically when only a deferrer default is set', function () {
    RateLimit::use(defaultDeferrer: new TestDeferrer);

    $instance = RateLimit::perMinute(5);

    expect($instance->getStore())->toBeInstanceOf(InMemoryStore::class)
        ->and($instance->getDeferrer())->toBeInstanceOf(TestDeferrer::class);

    RateLimit::use();
});

it('forwards an unmapped call to the underlying limiter', function () {
    $instance = RateLimit::perMinute(5);

    expect($instance->getLimit())->toBeInstanceOf(Limit::class);
});

it('forwards an unmapped call to the underlying limit', function () {
    $instance = RateLimit::perMinute(5);

    // maxAttempts lives on Limit, not RateLimit, so it goes through __call.
    $instance->maxAttempts(9, 'hour');

    expect($instance->getMaxAttempts())->toBe(9)
        ->and($instance->getTimespan())->toBe('hour');
});

it('resolves a redis store via the static fallback path', function () {
    RateLimit::use(defaultDeferrer: new TestDeferrer);
    config()->set('http-client-rate-limits.store', RedisStore::class);
    config()->set('http-client-rate-limits.redis_connection', 'cache');

    expect(RateLimit::perMinute(5)->getStore())->toBeInstanceOf(RedisStore::class);

    RateLimit::use();
});

it('throws via the static fallback when the store config is invalid', function () {
    RateLimit::use(defaultDeferrer: new TestDeferrer);
    config()->set('http-client-rate-limits.store', stdClass::class);

    try {
        RateLimit::perMinute(5);
    } finally {
        RateLimit::use();
    }
})->throws(InvalidStoreException::class);

it('throws via the static fallback when the deferrer config is invalid', function () {
    RateLimit::use(defaultStore: new TestStore);
    config()->set('http-client-rate-limits.deferrer', stdClass::class);

    try {
        RateLimit::perMinute(5);
    } finally {
        RateLimit::use();
    }
})->throws(InvalidDeferrerException::class);

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
