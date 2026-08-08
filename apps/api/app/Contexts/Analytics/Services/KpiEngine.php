<?php

namespace App\Contexts\Analytics\Services;

use App\Contexts\Analytics\Models\MetricSnapshot;
use App\Platform\Shared\Helpers\LocaleHelper;
use App\Platform\Shared\Services\BaseService;
use App\Platform\Shared\Tenancy\Contracts\CurrentTenantProvider;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Reads KPI values/series from the metric_snapshots READ MODEL only (never operational tables).
 * Results are cached.
 *
 * T1 tenant safety:
 *   - QUERY scope: MetricSnapshot uses BelongsToTenantNullable, so a resolved tenant's total()/series()
 *     already sum only GLOBAL + that org's buckets — org2 activity is filtered out at the DB.
 *   - CACHE key: the active tenant id is folded into the cache key (mirroring ReportCache's
 *     `?->value ?? 'global'` pattern), so a figure warmed for org1 is NEVER served to org2, and the
 *     platform/global figure (no resolved tenant, admin bypass) has its own 'global' bucket.
 */
class KpiEngine extends BaseService
{
    public function total(string $metricKey, CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) $this->cached("kpi:total:{$metricKey}:{$from->toDateString()}:{$to->toDateString()}", function () use ($metricKey, $from, $to) {
            return (int) MetricSnapshot::query()
                ->where('metric_key', $metricKey)
                ->whereBetween('period', [$from->toDateString(), $to->toDateString()])
                ->sum('value');
        });
    }

    /** @return array<int, array{period: string, value: int}> */
    public function series(string $metricKey, CarbonInterface $from, CarbonInterface $to): array
    {
        return (array) $this->cached("kpi:series:{$metricKey}:{$from->toDateString()}:{$to->toDateString()}", function () use ($metricKey, $from, $to) {
            return MetricSnapshot::query()
                ->where('metric_key', $metricKey)
                ->whereBetween('period', [$from->toDateString(), $to->toDateString()])
                ->selectRaw('period, SUM(value) as value')
                ->groupBy('period')
                ->orderBy('period')
                ->get()
                ->map(fn ($row) => ['period' => (string) $row->period, 'value' => (int) $row->value])
                ->all();
        });
    }

    private function cached(string $key, \Closure $callback): mixed
    {
        // Tenant-scoped: a KPI warmed for one org must never be served to another. Mirrors
        // ReportCache's `?->value ?? 'global'` — 'global' is the no-tenant/admin-bypass bucket.
        $tenant = app(CurrentTenantProvider::class)->currentTenant()?->value ?? 'global';

        // Locale-scoped: report/KPI payloads can embed localized labels, so entries must not be
        // shared across locales.
        return Cache::remember('analytics:'.$tenant.':'.LocaleHelper::current().':'.$key, (int) config('analytics.cache.ttl_seconds', 300), $callback);
    }
}
