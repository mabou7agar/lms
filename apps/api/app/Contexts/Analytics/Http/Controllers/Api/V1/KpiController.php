<?php

namespace App\Contexts\Analytics\Http\Controllers\Api\V1;

use App\Contexts\Analytics\Http\Controllers\Concerns\AuthorizesAnalytics;
use App\Contexts\Analytics\Http\Requests\KpiQueryRequest;
use App\Contexts\Analytics\Services\KpiEngine;
use App\Contexts\Analytics\Services\MetricsCatalog;
use App\Platform\Shared\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class KpiController extends Controller
{
    use AuthorizesAnalytics;

    public function index(KpiQueryRequest $request, KpiEngine $kpi, MetricsCatalog $catalog): JsonResponse
    {
        $this->assertCanViewAnalytics($request);
        $canSeeMoney = $this->canViewRevenue($request);

        $data = $request->validated();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from']) : CarbonImmutable::now()->subDays(30);
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : CarbonImmutable::now();

        // Day boundaries are computed in UTC, matching the persisted UTC snapshot columns. The KPI
        // query contract (KpiQueryRequest) intentionally carries no timezone, so widening it here
        // would be a contract change; a session/request timezone is therefore not applied at this
        // endpoint. The window helper accepts an optional zone for parity with ReportingEngine, but
        // this controller deliberately passes UTC.
        [$rangeStart, $rangeEnd] = $this->dayRange($from, $to);

        $kpis = [];
        foreach ($data['metrics'] as $key) {
            if (! $catalog->has($key)) {
                continue;
            }

            $unit = $catalog->definition($key)['unit'];

            // Money is dropped, not refused: a caller asking for a mixed set still gets the
            // metrics they are entitled to. Keyed on the unit rather than the metric name so a
            // future currency metric is covered the day it is added to the catalog.
            if ($unit === self::MONEY_UNIT && ! $canSeeMoney) {
                continue;
            }

            $kpis[] = [
                'metric' => $key,
                'unit' => $unit,
                'total' => $kpi->total($key, $rangeStart, $rangeEnd),
                'series' => $kpi->series($key, $rangeStart, $rangeEnd),
            ];
        }

        return ApiResponse::success(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'kpis' => $kpis]);
    }

    /**
     * The [start, end] instant window for the KPI query.
     *
     * $timezone defaults to UTC, where the boundaries are `startOfDay()`/`endOfDay()` in the
     * application timezone exactly as before — byte-for-byte unchanged. A valid IANA zone would
     * interpret the from/to calendar days in that zone and convert back to UTC; an unknown zone
     * falls through to the UTC path. This endpoint always passes UTC (see index()).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function dayRange(CarbonImmutable $from, CarbonImmutable $to, string $timezone = 'UTC'): array
    {
        if ($timezone === 'UTC' || ! in_array($timezone, timezone_identifiers_list(), true)) {
            return [$from->startOfDay(), $to->endOfDay()];
        }

        return [
            $from->shiftTimezone($timezone)->startOfDay()->utc(),
            $to->shiftTimezone($timezone)->endOfDay()->utc(),
        ];
    }
}
