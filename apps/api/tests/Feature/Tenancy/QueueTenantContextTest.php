<?php

declare(strict_types=1);

use App\Platform\Shared\Tenancy\Concerns\TenantAware;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Records the tenant a job actually runs under, so the test can assert restoration. */
final class TenantContextProbeState
{
    public static int|string|null $seen = 'unset';

    public static function reset(): void
    {
        self::$seen = 'unset';
    }
}

/** A queued job that captures its dispatching tenant and reports the tenant it runs under. */
final class TenantAwareProbeJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use TenantAware;

    public function __construct()
    {
        // Runs at dispatch time, on the originating (tenant-resolved) context.
        $this->captureTenantContext();
    }

    public function handle(): void
    {
        TenantContextProbeState::$seen = app(TenantContext::class)->id()?->value;
    }
}

beforeEach(function (): void {
    TenantContextProbeState::reset();
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

it('restores the dispatching tenant on the worker even when the ambient context is empty', function (): void {
    $context = app(TenantContext::class);

    // Dispatch under tenant 7...
    $context->set(TenantId::from(7));
    $job = new TenantAwareProbeJob;

    // ...then simulate a worker with no ambient tenant (no authenticated user).
    $context->forget();
    expect($context->id())->toBeNull();

    // Processing runs the job through the queue middleware pipeline (sync connection).
    dispatch($job);

    // The job saw tenant 7 (restored by the middleware), and the context is cleared afterwards.
    expect(TenantContextProbeState::$seen)->toBe(7)
        ->and($context->id())->toBeNull();
});

it('runs a job dispatched outside any tenant unscoped (backward compatible no-op)', function (): void {
    $context = app(TenantContext::class);
    $context->forget();

    $job = new TenantAwareProbeJob; // captures null
    dispatch($job);

    expect(TenantContextProbeState::$seen)->toBeNull();
});
