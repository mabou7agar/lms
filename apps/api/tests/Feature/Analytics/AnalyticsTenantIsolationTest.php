<?php

declare(strict_types=1);

use App\Contexts\Analytics\Models\MetricSnapshot;
use App\Contexts\Analytics\Services\KpiEngine;
use App\Contexts\Analytics\Services\MetricRollupService;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * T1 adversarial matrix for Analytics. Proves the metric_snapshots tenant dimension isolates orgs:
 *   - a resolved tenant reads GLOBAL + its OWN buckets, never another org's;
 *   - KpiEngine excludes another org's activity AND keys its cache per tenant (no cross-serve);
 *   - the rollup write path records per-tenant buckets;
 *   - with no resolved tenant (platform/admin view) everything is summed, exactly as before.
 *
 * These tests are the ONLY ones that establish a tenant context — the existing suite runs with
 * NULL-org users, so the nullable scope no-ops and behaviour is unchanged.
 */
beforeEach(function (): void {
    Cache::flush();
    app(TenantContext::class)->forget();
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

function seedMetric(): void
{
    $period = CarbonImmutable::now()->toDateString();

    MetricSnapshot::factory()->create(['metric_key' => 'enrollments', 'period' => $period, 'value' => 5]);              // global
    MetricSnapshot::factory()->forOrganization(1)->create(['metric_key' => 'enrollments', 'period' => $period, 'value' => 10]);
    MetricSnapshot::factory()->forOrganization(2)->create(['metric_key' => 'enrollments', 'period' => $period, 'value' => 20]);
}

it('reads GLOBAL plus the active org buckets, never another org (scope)', function (): void {
    seedMetric();

    app(TenantContext::class)->set(TenantId::from(1));

    expect(MetricSnapshot::query()->orderBy('value')->pluck('value')->all())->toBe([5, 10])
        ->and(MetricSnapshot::query()->where('value', 20)->exists())->toBeFalse();
});

it('KpiEngine total for org1 excludes org2 activity', function (): void {
    seedMetric();
    $from = CarbonImmutable::now()->subDay();
    $to = CarbonImmutable::now()->addDay();

    app(TenantContext::class)->set(TenantId::from(1));
    expect(app(KpiEngine::class)->total('enrollments', $from, $to))->toBe(15); // 5 global + 10 org1

    app(TenantContext::class)->set(TenantId::from(2));
    expect(app(KpiEngine::class)->total('enrollments', $from, $to))->toBe(25); // 5 global + 20 org2
});

it('KpiEngine cache warmed for org1 is never served to org2 (distinct cache entries)', function (): void {
    seedMetric();
    $from = CarbonImmutable::now()->subDay();
    $to = CarbonImmutable::now()->addDay();

    // Warm org1 (caches 15 under the org-1 key).
    app(TenantContext::class)->set(TenantId::from(1));
    $orgOne = app(KpiEngine::class)->total('enrollments', $from, $to);

    // org2 must NOT receive org1's cached figure — its own key computes 25.
    app(TenantContext::class)->set(TenantId::from(2));
    $orgTwo = app(KpiEngine::class)->total('enrollments', $from, $to);

    expect($orgOne)->toBe(15)->and($orgTwo)->toBe(25);
});

it('KpiEngine series for org1 excludes org2 activity', function (): void {
    seedMetric();
    $from = CarbonImmutable::now()->subDay();
    $to = CarbonImmutable::now()->addDay();

    app(TenantContext::class)->set(TenantId::from(1));
    $series = app(KpiEngine::class)->series('enrollments', $from, $to);

    expect(array_sum(array_column($series, 'value')))->toBe(15);
});

it('the rollup write path records a distinct bucket per tenant', function (): void {
    $rollup = new MetricRollupService;

    app(TenantContext::class)->set(TenantId::from(1));
    $rollup->increment('signups', 1);

    app(TenantContext::class)->set(TenantId::from(2));
    $rollup->increment('signups', 3);

    app(TenantContext::class)->forget();
    $rollup->increment('signups', 7); // global / no tenant

    $all = app(TenantContext::class)->runWithoutTenancy(
        static fn () => MetricSnapshot::where('metric_key', 'signups')->get()
    );

    expect($all)->toHaveCount(3)
        ->and((int) $all->firstWhere('organization_id', 1)->value)->toBe(1)
        ->and((int) $all->firstWhere('organization_id', 2)->value)->toBe(3)
        ->and((int) $all->firstWhere('organization_id', null)->value)->toBe(7);

    // org1 reads global + own only.
    app(TenantContext::class)->set(TenantId::from(1));
    $from = CarbonImmutable::now()->subDay();
    $to = CarbonImmutable::now()->addDay();
    expect(app(KpiEngine::class)->total('signups', $from, $to))->toBe(8); // 7 global + 1 org1
});

it('platform/global view (no resolved tenant) sums every bucket, as before', function (): void {
    seedMetric();
    $from = CarbonImmutable::now()->subDay();
    $to = CarbonImmutable::now()->addDay();

    // No tenant resolved -> scope no-ops -> the platform admin sees the cross-org total.
    expect(app(KpiEngine::class)->total('enrollments', $from, $to))->toBe(35); // 5 + 10 + 20
});

it('backward compatible: with no tenant the rollup keeps a single global bucket', function (): void {
    // Mirrors the existing MetricRollupTest expectation: NULL-org context => one global row.
    $rollup = new MetricRollupService;
    $rollup->increment('enrollments');
    $rollup->increment('enrollments', 4);

    expect(MetricSnapshot::where('metric_key', 'enrollments')->count())->toBe(1)
        ->and((int) MetricSnapshot::where('metric_key', 'enrollments')->first()->value)->toBe(5)
        ->and(MetricSnapshot::where('metric_key', 'enrollments')->first()->organization_id)->toBeNull();
});
