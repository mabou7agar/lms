<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 5 — Async Resilience. These pin the queue/Horizon invariants that keep background work
 * safe across worker restarts and failures. They are config- and hook-level: no domain job, no
 * notification/analytics/auth code is touched.
 */

// ---------------------------------------------------------------- retry / worker-restart safety

it('keeps the queue retry_after above every Horizon worker timeout', function () {
    // The canonical duplicate-processing bug: if retry_after <= a worker's timeout, a still-running
    // job is re-released and run again by a second worker. The exports supervisor runs up to 300s.
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    $timeouts = collect(config('horizon.defaults'))
        ->map(fn (array $supervisor): int => (int) ($supervisor['timeout'] ?? 60));

    expect($retryAfter)->toBeGreaterThan($timeouts->max());
    $timeouts->each(fn (int $timeout) => expect($retryAfter)->toBeGreaterThan($timeout));
});

it('keeps the database queue retry_after above the longest job timeout', function () {
    expect((int) config('queue.connections.database.retry_after'))->toBeGreaterThanOrEqual(300);
});

it('runs jobs only after the surrounding transaction commits', function () {
    // Restart safety: a job dispatched inside a transaction must never run against uncommitted data.
    expect(config('queue.connections.redis.after_commit'))->toBeTrue()
        ->and(config('queue.connections.database.after_commit'))->toBeTrue();
});

it('enables fast termination for resilient deploy restarts', function () {
    expect(config('horizon.fast_termination'))->toBeTrue();
});

it('recycles every worker process on a bounded time and job count', function () {
    collect(config('horizon.defaults'))->each(function (array $supervisor): void {
        expect((int) ($supervisor['maxTime'] ?? 0))->toBeGreaterThan(0)
            ->and((int) ($supervisor['maxJobs'] ?? 0))->toBeGreaterThan(0);
    });
});

// ---------------------------------------------------------------- dead-letter visibility

it('logs a structured record for every job that lands in failed_jobs', function () {
    Log::spy();

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SomeJob');
    $job->shouldReceive('getQueue')->andReturn('exports');
    $job->shouldReceive('uuid')->andReturn('uuid-123');
    $job->shouldReceive('attempts')->andReturn(3);
    // Horizon's ForgetJobTimer listener fires on JobFailed and reads the driver-level job id
    // (Illuminate\Contracts\Queue\Job::getJobId). Provide a stable fake so the real listener runs.
    $job->shouldReceive('getJobId')->andReturn('job-id-123');

    event(new JobFailed('redis', $job, new RuntimeException('boom')));

    Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
        return $message === 'queue.job_failed'
            && $context['job'] === 'App\\Jobs\\SomeJob'
            && $context['connection'] === 'redis'
            && $context['queue'] === 'exports'
            && $context['uuid'] === 'uuid-123'
            && $context['attempts'] === 3
            && $context['exception_class'] === RuntimeException::class
            && $context['exception_message'] === 'boom';
    })->once();
});

// ---------------------------------------------------------------- dead-letter retention schedule

it('schedules failed-job pruning on one server with the extended retention window', function () {
    // Force the console schedule (routes/console.php) to load, then inspect it.
    $this->artisan('schedule:list')->assertExitCode(0);

    $prune = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'queue:prune-failed'));

    expect($prune)->not->toBeNull()
        ->and($prune->command)->toContain('--hours=720')
        ->and($prune->onOneServer)->toBeTrue();
});
