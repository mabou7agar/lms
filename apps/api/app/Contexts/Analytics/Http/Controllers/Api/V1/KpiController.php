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
                'total' => $kpi->total($key, $from->startOfDay(), $to->endOfDay()),
                'series' => $kpi->series($key, $from->startOfDay(), $to->endOfDay()),
            ];
        }

        return ApiResponse::success(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'kpis' => $kpis]);
    }
}
