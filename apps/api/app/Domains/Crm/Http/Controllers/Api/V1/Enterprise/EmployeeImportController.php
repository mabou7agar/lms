<?php

namespace App\Domains\Crm\Http\Controllers\Api\V1\Enterprise;

use App\Domains\Crm\Http\Requests\Enterprise\EmployeeImportRequest;
use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Services\EmployeeCsvImporter;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Bulk employee CSV import for the enterprise portal. Owner/admin only. Without `commit` it returns a
 * DRY-RUN validation report (per-row valid/error/duplicate, formula-injection neutralized); with
 * `commit=true` it creates members (optionally inviting). The importer is tenant-scoped and never logs
 * PII.
 */
class EmployeeImportController extends EnterpriseController
{
    public function import(EmployeeImportRequest $request, EmployeeCsvImporter $importer): JsonResponse
    {
        Gate::authorize('manageMembers', OrganizationMember::class);

        $organization = $this->organization($request);

        $uploaded = $request->file('file');
        $csv = $uploaded instanceof UploadedFile ? (string) $uploaded->get() : '';

        $commit = $request->boolean('commit');

        if (! $commit) {
            return ApiResponse::success($importer->analyze($organization, $csv), 'Dry run complete.');
        }

        $result = $importer->commit($organization, $csv, $request->boolean('invite'));

        return ApiResponse::success($result, 'Import complete.');
    }
}
