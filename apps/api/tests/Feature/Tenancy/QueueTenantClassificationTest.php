<?php

declare(strict_types=1);

use App\Contexts\Analytics\Jobs\ProcessExportJob;
use App\Contexts\Analytics\Models\MetricSnapshot;
use App\Platform\Shared\Tenancy\Concerns\TenantAware;
use App\Platform\Shared\Tenancy\RestoreTenantContext;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * T1 QUEUE tenant-classification proof.
 *
 * Complements the kernel-level QueueTenantContextTest (which proves the TenantAware primitive on a
 * throwaway probe) by proving the primitive on a GENUINELY tenant-scoped workload: a job that reads the
 * org-scoped MetricSnapshot read model. Without restoration such a job would run on the worker with NO
 * authenticated user → tenant resolves null → it would sum EVERY org's buckets (a cross-tenant leak).
 * With TenantAware it restores the dispatching org, so org1's job sees org1 and the next job under org2
 * sees org2.
 *
 * This is exactly the shape of the one production job adopted this wave — ProcessExportJob — whose
 * dataset is built by KpiEngine over MetricSnapshot. That adoption is asserted directly below.
 */
uses(RefreshDatabase::class);

/** Records the org-scoped total a job actually computed on the worker. */
final class TenantScopedRollupProbeState
{
    public static ?int $total = null;

    public static function reset(): void
    {
        self::$total = null;
    }
}

/**
 * A tenant-aware queued job that reads the org-scoped MetricSnapshot read model. It stands in for any
 * analytics rollup/export job that attributes an organization: the sum it computes must reflect ONLY the
 * dispatching tenant's buckets (global + own), never another org's.
 */
final class TenantScopedRollupProbeJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use TenantAware;

    public function __construct(private readonly string $metricKey)
    {
        // Runs at dispatch time, on the originating (tenant-resolved) context.
        $this->captureTenantContext();
    }

    public function handle(): void
    {
        TenantScopedRollupProbeState::$total = (int) MetricSnapshot::query()
            ->where('metric_key', $this->metricKey)
            ->sum('value');
    }
}

beforeEach(function (): void {
    TenantScopedRollupProbeState::reset();
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

function seedRollupBuckets(string $metricKey): void
{
    app(TenantContext::class)->runWithoutTenancy(static function () use ($metricKey): void {
        foreach ([[null, 5], [1, 15], [2, 55]] as [$org, $value]) {
            MetricSnapshot::create([
                'organization_id' => $org,
                'metric_key' => $metricKey,
                'granularity' => 'daily',
                'period' => CarbonImmutable::today()->toDateString(),
                'dimension_key' => '',
                'dimension_value' => '',
                'value' => $value,
            ]);
        }
    });
}

it('restores org1 on the worker so a tenant-scoped job sums only global + org1', function (): void {
    $metric = 'queue.rollup.org1';
    seedRollupBuckets($metric); // global 5, org1 15, org2 55

    $context = app(TenantContext::class);

    // Dispatch under org1...
    $context->set(TenantId::from(1));
    $job = new TenantScopedRollupProbeJob($metric);

    // ...then simulate a worker with no ambient tenant.
    $context->forget();
    dispatch($job);

    // global (5) + org1 (15) = 20, never org2 (55).
    expect(TenantScopedRollupProbeState::$total)->toBe(20)
        ->and($context->id())->toBeNull(); // context cleared after the job
});

it('a subsequent job dispatched under org2 sees org2, proving no cross-job bleed', function (): void {
    $metric = 'queue.rollup.org2';
    seedRollupBuckets($metric);

    $context = app(TenantContext::class);

    $context->set(TenantId::from(2));
    $job = new TenantScopedRollupProbeJob($metric);
    $context->forget();
    dispatch($job);

    // global (5) + org2 (55) = 60, never org1 (15).
    expect(TenantScopedRollupProbeState::$total)->toBe(60);
});

it('a job dispatched outside any tenant runs unscoped (backward-compatible no-op)', function (): void {
    $metric = 'queue.rollup.global';
    seedRollupBuckets($metric);

    app(TenantContext::class)->forget();
    dispatch(new TenantScopedRollupProbeJob($metric)); // captures null

    // No tenant captured → scope dormant → every bucket summed (5 + 15 + 55).
    expect(TenantScopedRollupProbeState::$total)->toBe(75);
});

it('ProcessExportJob is TENANT-AWARE: it captures the dispatching org and restores it on the worker', function (): void {
    $context = app(TenantContext::class);

    // Constructed on a request-resolved context (org7) → captures the scalar tenant id.
    $context->set(TenantId::from(7));
    $job = new ProcessExportJob(123);

    expect($job->tenantContextId)->toBe(7);

    // Its middleware restores that tenant on the worker for the duration of handle().
    $middleware = $job->middleware();
    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(RestoreTenantContext::class);

    // Dispatched outside any tenant (platform admin / console) it captures null → runs unscoped, as before.
    $context->forget();
    $unscoped = new ProcessExportJob(123);
    expect($unscoped->tenantContextId)->toBeNull();
});
