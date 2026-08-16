<?php

use App\Domains\Crm\Http\Controllers\Api\V1\Enterprise\CompanyEntitlementController;
use App\Domains\Crm\Http\Controllers\Api\V1\Enterprise\CourseAssignmentController;
use App\Domains\Crm\Http\Controllers\Api\V1\Enterprise\DepartmentController;
use App\Domains\Crm\Http\Controllers\Api\V1\Enterprise\EmployeeImportController;
use App\Domains\Crm\Http\Controllers\Api\V1\Enterprise\InvitationController;
use App\Domains\Crm\Http\Controllers\Api\V1\Enterprise\ManagerReportController;
use App\Domains\Crm\Http\Controllers\Api\V1\Enterprise\MemberController;
use App\Domains\Crm\Http\Controllers\Api\V1\Enterprise\MemberImportController;
use App\Domains\Crm\Http\Controllers\Api\V1\Enterprise\OrgDataExportController;
use App\Domains\Crm\Http\Controllers\Api\V1\Enterprise\SeatController;
use App\Domains\Crm\Http\Controllers\Api\V1\Enterprise\TeamController;
use Illuminate\Support\Facades\Route;

/*
 | Signed export-file stream. NO auth guard: the signature authorizes the download, and the URL is
 | additionally org-bound + file-scoped inside the controller. Declared before the authed group so the
 | worker-produced bundle can be fetched without a session (e.g. by a BI connector).
 */
Route::prefix('v1/enterprise')->group(function (): void {
    Route::get('exports/{export}/file', [OrgDataExportController::class, 'file'])
        ->middleware('signed')
        ->name('enterprise.exports.file');
});

/*
 | ENTERPRISE MANAGER PORTAL (self-serve org operation). Authenticated. Base 'api' prefix + these =>
 | /api/v1/enterprise/*. Authority is resolved per-request from the caller's ManagerScope (tenant-
 | derived); a plain member is denied by the policies gated in each controller.
 */
Route::prefix('v1/enterprise')->middleware('auth:sanctum')->group(function (): void {
    // Manager learning report (org / department / team scoped) + CSV export.
    Route::get('report', [ManagerReportController::class, 'show']);
    Route::get('report/export', [ManagerReportController::class, 'export']);

    // Seat management.
    Route::get('seats', [SeatController::class, 'usage']);
    Route::get('seats/history', [SeatController::class, 'history']);
    Route::post('seats/assign', [SeatController::class, 'assign']);
    Route::post('seats/release', [SeatController::class, 'release']);
    Route::post('seats/resize', [SeatController::class, 'resize']);

    // Member management.
    Route::get('members', [MemberController::class, 'index']);
    Route::delete('members/{member}', [MemberController::class, 'remove']);
    Route::patch('members/{member}/role', [MemberController::class, 'changeRole']);
    Route::post('members/{member}/deactivate', [MemberController::class, 'deactivate']);

    // Department CRUD (+ assign manager).
    Route::get('departments', [DepartmentController::class, 'index']);
    Route::post('departments', [DepartmentController::class, 'store']);
    Route::patch('departments/{department}', [DepartmentController::class, 'update']);
    Route::delete('departments/{department}', [DepartmentController::class, 'destroy']);
    Route::post('departments/{department}/manager', [DepartmentController::class, 'assignManager']);

    // Team CRUD (+ assign manager).
    Route::get('teams', [TeamController::class, 'index']);
    Route::post('teams', [TeamController::class, 'store']);
    Route::patch('teams/{team}', [TeamController::class, 'update']);
    Route::delete('teams/{team}', [TeamController::class, 'destroy']);
    Route::post('teams/{team}/manager', [TeamController::class, 'assignManager']);

    // Invitation lifecycle (token-authorized; not manager-gated).
    Route::post('invitations/{token}/accept', [InvitationController::class, 'accept']);
    Route::post('invitations/{token}/decline', [InvitationController::class, 'decline']);

    // Bulk employee CSV import (dry-run by default; commit=true to write).
    Route::post('employees/import', [EmployeeImportController::class, 'import']);

    // Reusable CSV import framework — reference importer: bulk member import (dry-run by default).
    Route::post('imports/members', [MemberImportController::class, 'import']);

    // Course assignment: owner/admin grants published catalog courses to members/scopes.
    Route::post('course-assignments', [CourseAssignmentController::class, 'store']);

    // Purchased training: what the company bought, and handing those seats out. Distinct from the
    // free grant above — capacity, expiry and the product's reassignment policy are enforced here.
    Route::get('entitlements', [CompanyEntitlementController::class, 'index']);
    Route::get('entitlements/{entitlement}', [CompanyEntitlementController::class, 'show']);
    Route::post('entitlements/{entitlement}/assign', [CompanyEntitlementController::class, 'assign']);
    Route::post('entitlements/{entitlement}/revoke', [CompanyEntitlementController::class, 'revoke']);

    // Org BI/data export: queue a bundle, list, and inspect (download URLs are signed, in `show`).
    Route::get('exports', [OrgDataExportController::class, 'index']);
    Route::post('exports', [OrgDataExportController::class, 'store']);
    Route::get('exports/{export}', [OrgDataExportController::class, 'show']);
});
