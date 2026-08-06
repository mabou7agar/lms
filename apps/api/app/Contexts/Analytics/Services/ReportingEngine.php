<?php

namespace App\Contexts\Analytics\Services;

use App\Contexts\Analytics\Models\ReportDefinition;
use App\Platform\Shared\Services\BaseService;
use Carbon\CarbonImmutable;

/**
 * Runs a report definition by reading metric values from the snapshot read model.
 */
class ReportingEngine extends BaseService
{
    public function __construct(
        private readonly KpiEngine $kpi,
        private readonly FunnelService $funnel,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function run(ReportDefinition $report, array $params = []): array
    {
        $timezone = is_string($params['timezone'] ?? null) ? $params['timezone'] : 'UTC';
        [$from, $to] = $this->range($params, $timezone);
        $keys = (array) ($report->metric_keys ?? []);

        if ($report->type->value === 'funnel') {
            return ['type' => 'funnel', 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'steps' => $this->funnel->compute($keys, $from, $to)];
        }

        $rows = [];
        foreach ($keys as $key) {
            $rows[] = ['metric' => $key, 'total' => $this->kpi->total($key, $from, $to)];
        }

        return ['type' => 'metric', 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $rows];
    }

    /**
     * Resolve the [from, to] instant window for the report.
     *
     * $timezone defaults to UTC: with the default the day boundaries are computed exactly as before
     * (in the application timezone, which is UTC, matching the persisted UTC snapshot columns), so
     * existing callers are byte-for-byte unchanged. When a caller supplies a valid IANA zone, the
     * from/to calendar days are interpreted in that zone and the resulting instants converted back to
     * UTC for the query; an unknown zone falls through to the UTC path rather than throwing.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(array $params, string $timezone = 'UTC'): array
    {
        $from = isset($params['from']) ? CarbonImmutable::parse($params['from']) : CarbonImmutable::now()->subDays(30);
        $to = isset($params['to']) ? CarbonImmutable::parse($params['to']) : CarbonImmutable::now();

        if ($timezone === 'UTC' || ! in_array($timezone, timezone_identifiers_list(), true)) {
            return [$from->startOfDay(), $to->endOfDay()];
        }

        return [
            $from->shiftTimezone($timezone)->startOfDay()->utc(),
            $to->shiftTimezone($timezone)->endOfDay()->utc(),
        ];
    }
}
