<?php

use App\Domains\Authoring\Http\Controllers\Api\V1\Admin\ContentVersionAdminController;
use Illuminate\Support\Facades\Route;

/*
 | P2/W03 - Content versioning admin API (authenticated). Mounted at /api/v1/admin.
 | `{course}` is a plain string (course public_id) resolved via CourseAccessPort — no model binding.
 | `{version}` is route-bound to a ContentVersion by public_id.
 */
Route::prefix('v1/admin')->middleware('auth:sanctum')->group(function (): void {
    Route::get('courses/{course}/versions', [ContentVersionAdminController::class, 'index']);
    Route::post('courses/{course}/versions', [ContentVersionAdminController::class, 'store']);

    Route::get('versions/{version}', [ContentVersionAdminController::class, 'show']);
    Route::post('versions/{version}/restore', [ContentVersionAdminController::class, 'restore']);
    Route::post('versions/{version}/rollback', [ContentVersionAdminController::class, 'rollback']);
    Route::post('versions/{version}/clone', [ContentVersionAdminController::class, 'clone']);
    Route::post('versions/{version}/fork', [ContentVersionAdminController::class, 'fork']);
});
