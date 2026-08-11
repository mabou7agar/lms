<?php

namespace App\Domains\Crm\Http\Controllers\Api\V1\Enterprise;

use App\Domains\Crm\Actions\Organization\CreateOrgExportAction;
use App\Domains\Crm\Http\Resources\OrgDataExportResource;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Models\OrgDataExport;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Org BI/data export: an owner/admin queues a tenant-confined export of the organization's OWN data
 * (member roster + seat usage + org-level learning/analytics aggregates); a worker builds it into a
 * flat-CSV bundle + JSON manifest; and the owner downloads each file through a short-lived, signed,
 * org-bound URL.
 *
 * ISOLATION: every read confines on the caller's resolved-tenant organization (never client input), so
 * one org can neither queue, list, inspect, nor download another org's export — a foreign export id
 * simply 404s. Producing a durable, roster-bearing artifact is an owner/admin capability (exportData),
 * a strictly stronger authority than reading a figure on screen.
 */
class OrgDataExportController extends EnterpriseController
{
    /** Queue a new export for the caller's organization. */
    public function store(Request $request, CreateOrgExportAction $action): JsonResponse
    {
        Gate::authorize('exportData', OrganizationMember::class);

        $organization = $this->organization($request);

        $export = $action->executeForOrganization((int) $organization->id, $this->actor($request)->actorId());

        return ApiResponse::created(new OrgDataExportResource($export), 'Export queued.');
    }

    /** List the caller organization's exports (most recent first). */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('exportData', OrganizationMember::class);

        $organization = $this->organization($request);

        $exports = OrgDataExport::query()
            ->where('organization_id', $organization->id)
            ->latest('id')
            ->paginate(20);

        return ApiResponse::paginated($exports, OrgDataExportResource::class);
    }

    /** Show one export, plus (when completed) signed per-file download URLs. */
    public function show(Request $request, string $export): JsonResponse
    {
        Gate::authorize('exportData', OrganizationMember::class);

        $organization = $this->organization($request);
        $job = $this->findForOrganization($export, (int) $organization->id);

        $downloads = [];
        if ($job->isCompleted()) {
            $ttl = now()->addMinutes((int) config('crm.export.download_ttl_minutes', 15));
            $manifest = (array) $job->manifest;
            $files = array_merge(
                array_map(static fn ($f): string => (string) (is_array($f) ? ($f['file'] ?? '') : ''), (array) ($manifest['files'] ?? [])),
                ['manifest.json'],
            );

            foreach ($files as $file) {
                $downloads[$file] = URL::temporarySignedRoute('enterprise.exports.file', $ttl, [
                    'export' => $job->public_id,
                    'organization' => $organization->public_id,
                    'file' => $file,
                ]);
            }
        }

        return ApiResponse::success([
            'export' => (new OrgDataExportResource($job))->resolve(),
            'downloads' => $downloads,
        ]);
    }

    /**
     * Stream one file from an export bundle. No auth guard — the signature authorizes — but the URL is
     * org-bound: the organization it was minted for must own the export, and the requested file must be
     * one the manifest actually produced, so a forged/probed path cannot escape the bundle.
     */
    public function file(Request $request, string $export): mixed
    {
        $job = OrgDataExport::where('public_id', $export)->first();

        if ($job === null || ! $job->isCompleted()) {
            throw new NotFoundHttpException('Export not available.');
        }

        $organization = Organization::where('public_id', (string) $request->query('organization'))->first();
        if ($organization === null || (int) $organization->id !== (int) $job->organization_id) {
            throw new NotFoundHttpException('Export not available.');
        }

        $file = (string) $request->query('file');
        if (! $this->isAllowedFile($job, $file)) {
            throw new NotFoundHttpException('File not found in export.');
        }

        $disk = Storage::disk((string) config('crm.export.disk', 'local'));
        $path = $job->storage_prefix.'/'.$file;

        if (! $disk->exists($path)) {
            throw new NotFoundHttpException('File not found in export.');
        }

        $isManifest = $file === 'manifest.json';

        return response($disk->get($path), 200, [
            'Content-Type' => $isManifest ? 'application/json' : 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
        ]);
    }

    private function findForOrganization(string $publicId, int $organizationId): OrgDataExport
    {
        $export = OrgDataExport::where('public_id', $publicId)
            ->where('organization_id', $organizationId)
            ->first();

        if ($export === null) {
            throw new NotFoundHttpException('Export not found.');
        }

        return $export;
    }

    private function isAllowedFile(OrgDataExport $job, string $file): bool
    {
        if ($file === 'manifest.json') {
            return true;
        }

        $manifest = (array) $job->manifest;

        foreach ((array) ($manifest['files'] ?? []) as $entry) {
            if (is_array($entry) && ($entry['file'] ?? null) === $file) {
                return true;
            }
        }

        return false;
    }
}
