<?php

namespace App\Contexts\Analytics\Http\Controllers\Api\V1;

use App\Contexts\Analytics\Actions\RunReportAction;
use App\Contexts\Analytics\Http\Controllers\Concerns\AuthorizesAnalytics;
use App\Contexts\Analytics\Http\Requests\RunReportRequest;
use App\Contexts\Analytics\Http\Resources\ReportDefinitionResource;
use App\Contexts\Analytics\Models\ReportDefinition;
use App\Contexts\Analytics\Services\MetricsCatalog;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReportController extends Controller
{
    use AuthorizesAnalytics;

    public function index(Request $request): JsonResponse
    {
        $this->assertCanViewAnalytics($request);

        $reports = ReportDefinition::query()->latest('id')->get();

        return ApiResponse::success(ReportDefinitionResource::collection($reports));
    }

    public function show(Request $request, ReportDefinition $report): JsonResponse
    {
        $this->assertCanViewAnalytics($request);

        return ApiResponse::success(new ReportDefinitionResource($report));
    }

    public function run(RunReportRequest $request, RunReportAction $action, MetricsCatalog $catalog): JsonResponse
    {
        $this->assertCanViewAnalytics($request);

        $data = $request->validated();
        $report = ReportDefinition::where('public_id', $data['report'])->first();

        if ($report === null) {
            throw new NotFoundHttpException('Report not found.');
        }

        // Unlike the KPI endpoint, a report cannot have its money metrics quietly dropped — the
        // result is a single computed artifact, and returning a partial one under the same name
        // would misrepresent it. So a money-bearing report is refused outright.
        if ($this->includesMoney($report, $catalog) && ! $this->canViewRevenue($request)) {
            throw new AccessDeniedHttpException('This report includes revenue and requires additional permission.');
        }

        $run = $action->execute($report, ['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null]);

        return ApiResponse::success([
            'run_id' => $run->public_id,
            'ran_at' => $run->ran_at?->toIso8601String(),
            'result' => $run->result,
        ], 'Report generated.');
    }

    /** Does this definition reference any currency-denominated metric? */
    private function includesMoney(ReportDefinition $report, MetricsCatalog $catalog): bool
    {
        $keys = $report->metric_keys;

        if (! is_array($keys)) {
            return false;
        }

        foreach ($keys as $key) {
            if (is_string($key) && $catalog->has($key) && $catalog->definition($key)['unit'] === self::MONEY_UNIT) {
                return true;
            }
        }

        return false;
    }
}
