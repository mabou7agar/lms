<?php

declare(strict_types=1);

namespace App\Contexts\Analytics\Services;

use App\Contexts\Analytics\Models\MetricSnapshot;
use App\Platform\Shared\Analytics\Contracts\AnalyticsSummaryPort;
use App\Platform\Shared\Analytics\Data\AnalyticsSummary;
use App\Platform\Shared\Analytics\Data\MetricValue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Analytics' implementation of the Shared {@see AnalyticsSummaryPort}. Composes an aggregate KPI
 * summary from the metric_snapshots READ MODEL only — never operational tables, never a per-learner row.
 *
 * Tenant scope is applied EXPLICITLY from the $organizationId the caller passes, NOT from ambient
 * request state: an organization sees GLOBAL (organization_id IS NULL) plus its OWN buckets and never
 * another org's; a null organization (super_admin) sees platform-wide totals. The read drops the
 * model's ambient tenant global scope and re-applies the intended filter itself, so it is correct
 * regardless of what tenant the request happens to have resolved.
 *
 * Money gating: currency-denominated metrics (revenue, paid orders) are queried ONLY when the caller
 * is permitted; otherwise they are OMITTED from the summary entirely (not zeroed, not nulled), so the
 * assistant cannot cite a figure the administrator may not see.
 */
final class AnalyticsSummaryProvider implements AnalyticsSummaryPort
{
    /** Engagement metrics every permitted administrator sees. */
    private const GENERAL_METRICS = ['signups', 'enrollments', 'completions', 'certificates_issued'];

    /** Currency-denominated metrics, included only when the caller holds the revenue permission. */
    private const MONEY_METRICS = ['revenue', 'orders_paid'];

    public function __construct(
        private readonly MetricsCatalog $catalog,
    ) {}

    public function summarize(bool $includeMoney, ?int $organizationId): AnalyticsSummary
    {
        $days = max(1, (int) config('ai.assistant.summary_window_days', 30));
        $to = CarbonImmutable::now();
        $rangeStart = $to->subDays($days)->startOfDay();
        $rangeEnd = $to->endOfDay();

        /** @var array<string, MetricValue> $metrics */
        $metrics = [];

        foreach (self::GENERAL_METRICS as $key) {
            if ($this->catalog->has($key)) {
                $metrics[$key] = MetricValue::of($this->total($key, $rangeStart, $rangeEnd, $organizationId));
            }
        }

        // Derived: completion rate (%) as completions / enrollments. noData (not 0%) when there is
        // nothing to divide by, so the UI/assistant never states a false "0% completion".
        $enrollments = isset($metrics['enrollments']) ? (int) $metrics['enrollments']->value : 0;
        $completions = isset($metrics['completions']) ? (int) $metrics['completions']->value : 0;
        $metrics['completion_rate'] = $enrollments > 0
            ? MetricValue::of(round($completions / $enrollments * 100, 1))
            : MetricValue::noData('No enrollments in the selected period.');

        // Money metrics are appended ONLY when permitted — otherwise absent from the map.
        if ($includeMoney) {
            foreach (self::MONEY_METRICS as $key) {
                if ($this->catalog->has($key)) {
                    $metrics[$key] = MetricValue::of($this->total($key, $rangeStart, $rangeEnd, $organizationId));
                }
            }
        }

        return new AnalyticsSummary(
            from: $rangeStart->toDateString(),
            to: $rangeEnd->toDateString(),
            metrics: $metrics,
            topCourses: $this->topCourses($rangeStart, $rangeEnd, $organizationId),
            moneyIncluded: $includeMoney,
        );
    }

    /** Sum of a metric over the window, confined to the given organization (global + own). */
    private function total(string $metricKey, CarbonImmutable $from, CarbonImmutable $to, ?int $organizationId): int
    {
        return (int) $this->scoped($organizationId)
            ->where('metric_key', $metricKey)
            ->whereBetween('period', [$from->toDateString(), $to->toDateString()])
            ->sum('value');
    }

    /**
     * Aggregate enrollments per course from the read model's course dimension. Pre-aggregated sums
     * keyed by a course label — no learner rows are touched, so this is PII-free.
     *
     * @return array<int, array{label: string, enrollments: int}>
     */
    private function topCourses(CarbonImmutable $from, CarbonImmutable $to, ?int $organizationId): array
    {
        $limit = max(1, (int) config('ai.assistant.top_courses', 5));

        return $this->scoped($organizationId)
            ->where('metric_key', 'enrollments')
            ->where('dimension_key', 'course')
            ->whereBetween('period', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('dimension_value as label, SUM(value) as enrollments')
            ->groupBy('dimension_value')
            ->orderByDesc('enrollments')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'enrollments' => (int) $row->enrollments,
            ])
            ->all();
    }

    /**
     * A MetricSnapshot query scoped EXPLICITLY (not via ambient tenant state): the model's tenant
     * global scope is dropped and the intended filter re-applied — an organization sees GLOBAL
     * (organization_id NULL) plus its OWN buckets; a null organization (super_admin) sees every bucket.
     *
     * @return Builder<MetricSnapshot>
     */
    private function scoped(?int $organizationId): Builder
    {
        $query = MetricSnapshot::query()->withoutGlobalScopes();

        if ($organizationId !== null) {
            $query->where(function (Builder $inner) use ($organizationId): void {
                $inner->whereNull('organization_id')->orWhere('organization_id', $organizationId);
            });
        }

        return $query;
    }
}
