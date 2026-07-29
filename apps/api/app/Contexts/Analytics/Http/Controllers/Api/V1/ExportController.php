<?php

namespace App\Contexts\Analytics\Http\Controllers\Api\V1;

use App\Contexts\Analytics\Actions\CreateExportJobAction;
use App\Contexts\Analytics\Http\Controllers\Concerns\AuthorizesAnalytics;
use App\Contexts\Analytics\Http\Requests\CreateExportRequest;
use App\Contexts\Analytics\Http\Resources\ExportJobResource;
use App\Contexts\Analytics\Models\ExportJob;
use App\Contexts\Analytics\Models\ReportDefinition;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Analytics exports.
 *
 * `store` previously carried no authorization beyond `auth:sanctum`, so any authenticated user —
 * including a learner — could queue an export of a report they were never entitled to read. It is
 * now gated on the `analytics.export` permission, which existed and was seeded but had never been
 * enforced anywhere.
 *
 * `analytics.export` rather than `analytics.view` deliberately: producing a durable, downloadable
 * artifact is a stronger capability than reading a figure on screen, and the two are separately
 * grantable. Instructors hold neither, so they cannot export; the permission is seeded to `admin`
 * only, and super_admin passes through the same bypass every analytics policy uses.
 */
class ExportController extends Controller
{
    use AuthorizesAnalytics;

    public function store(CreateExportRequest $request, CreateExportJobAction $action): JsonResponse
    {
        $this->assertCanExportAnalytics($request);

        $data = $request->validated();
        $report = ReportDefinition::where('public_id', $data['report'])->first();
        if ($report === null) {
            throw new NotFoundHttpException('Report not found.');
        }

        $job = $action->executeByUserId($request->user()->id, $data['format'], 'report', [
            'report_definition_id' => $report->id,
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
        ]);

        return ApiResponse::created(new ExportJobResource($job), 'Export queued.');
    }

    public function show(Request $request, ExportJob $export): JsonResponse
    {
        Gate::authorize('view', $export);

        $download = null;
        if ($export->isCompleted()) {
            // M1: bind the owning user's id into the signature (owner) so the stream route can
            // re-check it — a leaked signed URL cannot be used to fetch another user's export.
            $download = URL::temporarySignedRoute(
                'analytics.exports.file',
                now()->addMinutes((int) config('analytics.export.download_ttl_minutes', 15)),
                ['export' => $export->public_id, 'owner' => $export->user_id],
            );
        }

        return ApiResponse::success([
            'export' => (new ExportJobResource($export))->resolve(),
            'download_url' => $download,
        ]);
    }

    public function file(Request $request, string $export): mixed
    {
        $job = ExportJob::where('public_id', $export)->first();
        if ($job === null || ! $job->isCompleted()) {
            throw new NotFoundHttpException('Export not available.');
        }

        // M1 — ownership as well as signature. The signature is valid, but we still require the
        // owner bound at mint time to match the resolved job, so a leaked/replayed URL can never be
        // pointed at a different user's export.
        if ((int) $request->query('owner') !== (int) $job->user_id) {
            throw new AccessDeniedHttpException('You are not authorized to access this export.');
        }

        $disk = Storage::disk((string) config('analytics.export.disk', 'local'));

        return response($disk->get($job->file_path), 200, [
            'Content-Type' => $job->format->value === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'text/csv',
            'Content-Disposition' => 'attachment; filename="export-'.$job->public_id.'.'.$job->format->value.'"',
        ]);
    }
}
