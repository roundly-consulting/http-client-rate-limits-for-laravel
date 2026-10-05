<?php

declare(strict_types=1);

use RoundlyConsulting\HttpClientRateLimits\Deferrer\ReleaseDeferrer;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidDeferrerException;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\JobReleasedException;

it('returns a millisecond timestamp', function () {
    $job = new class
    {
        public int $released = 0;

        public function release(int $delay): void
        {
            $this->released = $delay;
        }
    };

    expect((new ReleaseDeferrer($job))->timestamp())->toBeInt()->toBeGreaterThan(0);
});

it('releases the job with the delay in seconds and throws to unwind', function () {
    $job = new class
    {
        public int $released = -1;

        public function release(int $delay): void
        {
            $this->released = $delay;
        }
    };

    $deferrer = new ReleaseDeferrer($job);

    try {
        // 4200ms rounds up to 5 seconds.
        $deferrer->defer(4_200, 'global');
        $this->fail('Expected JobReleasedException.');
    } catch (JobReleasedException $e) {
        expect($job->released)->toBe(5)
            ->and($e->delaySeconds)->toBe(5);
    }
});

it('releases the job only on its first defer and unwinds on every one', function () {
    $job = new class
    {
        /** @var list<int> */
        public array $released = [];

        public function release(int $delay): void
        {
            $this->released[] = $delay;
        }
    };

    $deferrer = new ReleaseDeferrer($job);

    expect(fn () => $deferrer->defer(4_200, 'api'))->toThrow(JobReleasedException::class)
        ->and(fn () => $deferrer->defer(9_000, 'other'))->toThrow(
            JobReleasedException::class,
            'Released job for [other] back onto the queue with a 5s delay.',
        )
        ->and($job->released)->toBe([5]);
});

it('throws when the job does not expose a release method', function () {
    new ReleaseDeferrer(new stdClass);
})->throws(InvalidDeferrerException::class);
