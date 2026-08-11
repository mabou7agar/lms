<?php

namespace App\Domains\Crm\Http\Controllers\Api\V1\Enterprise;

use App\Domains\Crm\Http\Requests\Enterprise\MemberImportRequest;
use App\Domains\Crm\Import\MemberImportPipeline;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Reference endpoint for the reusable CSV import framework: bulk organization-member import. Owner/admin
 * only, tenant-scoped. Without `commit` it returns a DRY-RUN validation report (per-row
 * valid/error/duplicate, formula-injection neutralized, malformed rows surfaced); with `commit=true` it
 * idempotently upserts the valid rows. The dry run never writes; the commit re-validates from scratch.
 */
class MemberImportController extends EnterpriseController
{
    public function import(MemberImportRequest $request, MemberImportPipeline $pipeline): JsonResponse
    {
        Gate::authorize('manageMembers', OrganizationMember::class);

        $organization = $this->organization($request);

        $uploaded = $request->file('file');
        $csv = $uploaded instanceof UploadedFile ? (string) $uploaded->get() : '';

        if (! $request->boolean('commit')) {
            return ApiResponse::success($pipeline->analyze($organization, $csv), 'Dry run complete.');
        }

        return ApiResponse::success($pipeline->commit($organization, $csv), 'Import complete.');
    }
}
