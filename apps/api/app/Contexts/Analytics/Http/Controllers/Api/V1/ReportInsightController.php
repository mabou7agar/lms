<?php

namespace App\Contexts\Analytics\Http\Controllers\Api\V1;

use App\Contexts\Analytics\Http\Requests\ReportInsightRequest;
use App\Contexts\Analytics\Http\Resources\ReportInsightResource;
use App\Contexts\Analytics\Services\ReportCache;
use App\Contexts\Analytics\Services\Reports\ReportingService;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Operational reports API (/api/v1/reports/insights/*). Every action is gated to admin /
 * super_admin (defense-in-depth alongside the frontend gate) and delegates the real aggregation to
 * the ReportingService. One endpoint per report; the tabular reports also accept page/per_page.
 */
class ReportInsightController extends Controller
{
    public function __construct(
        private readonly ReportingService $reports,
        private readonly ReportCache $cache,
    ) {}

    public function catalog(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        return ApiResponse::success($this->reports->catalog());
    }

    public function revenue(ReportInsightRequest $request): JsonResponse
    {
        return $this->respond($request, 'revenue', fn (CarbonImmutable $from, CarbonImmutable $to): array => $this->reports->revenue($from, $to));
    }

    public function commerce(ReportInsightRequest $request): JsonResponse
    {
        return $this->respond($request, 'commerce', fn (CarbonImmutable $from, CarbonImmutable $to): array => $this->reports->commerce($from, $to));
    }

    public function coursePerformance(ReportInsightRequest $request): JsonResponse
    {
        return $this->respond($request, 'course_performance', fn (CarbonImmutable $from, CarbonImmutable $to, int $page, int $perPage): array => $this->reports->coursePerformance($from, $to, $page, $perPage));
    }

    public function instructorPerformance(ReportInsightRequest $request): JsonResponse
    {
        return $this->respond($request, 'instructor_performance', fn (CarbonImmutable $from, CarbonImmutable $to, int $page, int $perPage): array => $this->reports->instructorPerformance($from, $to, $page, $perPage));
    }

    public function organizationPerformance(ReportInsightRequest $request): JsonResponse
    {
        return $this->respond($request, 'organization_performance', fn (CarbonImmutable $from, CarbonImmutable $to, int $page, int $perPage): array => $this->reports->organizationPerformance($from, $to, $page, $perPage));
    }

    public function certificates(ReportInsightRequest $request): JsonResponse
    {
        return $this->respond($request, 'certificates', fn (CarbonImmutable $from, CarbonImmutable $to): array => $this->reports->certificates($from, $to));
    }

    public function liveSessions(ReportInsightRequest $request): JsonResponse
    {
        return $this->respond($request, 'live_sessions', fn (CarbonImmutable $from, CarbonImmutable $to): array => $this->reports->liveSessions($from, $to));
    }

    public function learnerActivity(ReportInsightRequest $request): JsonResponse
    {
        return $this->respond($request, 'learner_activity', fn (CarbonImmutable $from, CarbonImmutable $to): array => $this->reports->learnerActivity($from, $to));
    }

    public function completionFunnel(ReportInsightRequest $request): JsonResponse
    {
        return $this->respond($request, 'completion_funnel', fn (CarbonImmutable $from, CarbonImmutable $to): array => $this->reports->completionFunnel($from, $to));
    }

    public function retention(ReportInsightRequest $request): JsonResponse
    {
        return $this->respond($request, 'retention', fn (CarbonImmutable $from, CarbonImmutable $to): array => $this->reports->retention($from, $to));
    }

    public function crm(ReportInsightRequest $request): JsonResponse
    {
        return $this->respond($request, 'crm', fn (CarbonImmutable $from, CarbonImmutable $to): array => $this->reports->crm($from, $to));
    }

    /**
     * Shared pipeline: authorize, resolve the [from, to] window + pagination, compute (cached), wrap.
     *
     * Authorization runs BEFORE the cache read, so an unauthorized caller never touches a cached
     * entry. The cache key discriminates by tenant + report + window + pagination; the response
     * metadata is recomputed each call (identical for the same window), so a cache hit is byte-for-
     * byte the same response as a miss.
     *
     * @param  Closure(CarbonImmutable, CarbonImmutable, int, int): array<string, mixed>  $compute
     */
    private function respond(ReportInsightRequest $request, string $report, Closure $compute): JsonResponse
    {
        $this->assertAdmin($request);
        [$from, $to] = $this->range($request);
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(100, max(1, (int) $request->integer('per_page', 20)));

        /** @var array<string, mixed> $data */
        $data = $this->cache->remember(
            $report,
            [$from->toDateString(), $to->toDateString(), $page, $perPage],
            static fn (): array => $compute($from, $to, $page, $perPage),
        );

        return ApiResponse::success(
            new ReportInsightResource($data),
            null,
            200,
            ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        );
    }

    /**
     * Resolve the reporting window. Defaults to the trailing 12 months (aligned to month start) so
     * monthly series are meaningful out of the box.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(ReportInsightRequest $request): array
    {
        $to = $request->filled('to')
            ? CarbonImmutable::parse((string) $request->input('to'))
            : CarbonImmutable::now();

        $from = $request->filled('from')
            ? CarbonImmutable::parse((string) $request->input('from'))
            : $to->subMonths(12)->startOfMonth();

        return [$from->startOfDay(), $to->endOfDay()];
    }

    private function assertAdmin(Request $request): void
    {
        $user = $request->user();

        if (! $user instanceof Actor || (! $user->hasRole('admin') && ! $user->hasRole('super_admin'))) {
            throw new AccessDeniedHttpException('Administrator access required.');
        }
    }
}
