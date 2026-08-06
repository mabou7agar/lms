<?php

namespace App\Contexts\Analytics\Services;

use App\Contexts\Analytics\Models\MetricSnapshot;
use App\Platform\Shared\Helpers\LocaleHelper;
use App\Platform\Shared\Services\BaseService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Reads KPI values/series from the metric_snapshots READ MODEL only (never operational tables).
 * Results are cached.
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
        // Locale-scoped: report/KPI payloads can embed localized labels, so entries must not be
        // shared across locales.
        return Cache::remember('analytics:'.LocaleHelper::current().':'.$key, (int) config('analytics.cache.ttl_seconds', 300), $callback);
    }
}
