<?php

declare(strict_types=1);

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\JobReleasedException;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;
use RoundlyConsulting\HttpClientRateLimits\Jobs\Middleware\HandlesRateLimitRelease;
use RoundlyConsulting\HttpClientRateLimits\Tests\Support\RateLimitedJob;

/**
 * A real `database` queue and worker, so the release is judged by what Laravel's worker
 * actually does with it — reports, JobExceptionOccurred, `$maxExceptions`, failed_jobs.
 */
function useDatabaseQueue(): void
{
    config()->set('cache.default', 'array');
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]);
    config()->set('queue.failed', ['driver' => 'database-uuids', 'database' => config('database.default'), 'table' => 'failed_jobs']);

    Schema::create('jobs', function ($table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    Schema::create('failed_jobs', function ($table): void {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
}

function workOneJob(): void
{
    DB::table('jobs')->update(['available_at' => 0]); // run the released copy at once

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);
}

// Bug: the ReleaseDeferrer's unwind reached the worker as a job exception: reported, counted
// toward $maxExceptions, and the job was failed while its released copy was still queued.
it('releases a rate-limited job without the worker ever seeing an exception', function () {
    useDatabaseQueue();
    Http::fake();
    $exceptions = 0;
    $failures = 0;
    $processed = 0;
    Event::listen(JobExceptionOccurred::class, function () use (&$exceptions): void {
        $exceptions++;
    });
    Event::listen(JobFailed::class, function () use (&$failures): void {
        $failures++;
    });
    Event::listen(JobProcessed::class, function () use (&$processed): void {
        $processed++;
    });

    Http::rateLimit(RateLimits::perHour(1)->by('job-demo'))->get('https://api.example.com/first'); // window full
    RateLimitedJob::dispatch();

    foreach (range(1, 3) as $ignored) {
        workOneJob();
    }

    expect($exceptions)->toBe(0)
        ->and($failures)->toBe(0)
        ->and($processed)->toBe(3)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->value('attempts'))->toBe(3);

    Http::assertSentCount(1); // the job never sent while the window was full
});

it('ends only its own job\'s attempt', function () {
    $job = new stdClass;
    $middleware = new HandlesRateLimitRelease;

    expect($middleware->handle($job, fn () => 'done'))->toBe('done')
        ->and($middleware->handle($job, fn () => throw new JobReleasedException(5, 'api', $job)))->toBeNull()
        ->and(fn () => $middleware->handle($job, fn () => throw new JobReleasedException(5, 'api', new stdClass)))
        ->toThrow(JobReleasedException::class);
});

// Bug: the exception always named the key [global], whatever limit released the job.
it('names the limit key that released the job', function () {
    $job = new class
    {
        public function release(int $delay): void {}
    };

    Http::fake();
    Http::rateLimit(RateLimits::perHour(1)->by('reports'))->get('https://api.example.com/first');

    try {
        Http::withMiddleware(RateLimits::releasingJob($job)->perHour(1)->by('reports'))->get('https://api.example.com/next');
        $this->fail('Expected the job to be released.');
    } catch (JobReleasedException $exception) {
        expect($exception->key)->toBe('reports')
            ->and($exception->job)->toBe($job)
            ->and($exception->getMessage())->toStartWith('Released job for [reports]');
    }
});
