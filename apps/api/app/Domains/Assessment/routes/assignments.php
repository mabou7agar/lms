<?php

use App\Domains\Assessment\Http\Controllers\Api\V1\Admin\AssignmentAdminController;
use App\Domains\Assessment\Http\Controllers\Api\V1\Admin\GradebookController;
use App\Domains\Assessment\Http\Controllers\Api\V1\Admin\SubmissionReviewController;
use App\Domains\Assessment\Http\Controllers\Api\V1\Learner\LearnerAssignmentController;
use App\Domains\Assessment\Http\Controllers\Api\V1\Learner\SubmissionController;
use Illuminate\Support\Facades\Route;

/**
 * Assignments, submissions and the gradebook. Registered by AssessmentServiceProvider (add
 * 'routes/assignments.php' to $routeFiles).
 *
 * `{course}` is a plain string (course public_id) resolved through CourseAccessPort. Assignments,
 * submissions and files are route-bound by public_id. Instructor routes sit under v1/admin; learner
 * routes under v1. All are auth:sanctum.
 *
 * NOTE for the integrator: learner and admin both bind `assignments/{assignment}` and
 * `submissions/{submission}`. Because the two groups carry different prefixes (v1 vs v1/admin) they
 * do not collide. Keep the learner GET `assignments/{assignment}` returning the LEARNER resource.
 */

// ── Instructor / authoring + grading ───────────────────────────────────────
Route::prefix('v1/admin')->middleware('auth:sanctum')->group(function (): void {
    Route::get('courses/{course}/assignments', [AssignmentAdminController::class, 'index']);
    Route::post('courses/{course}/assignments', [AssignmentAdminController::class, 'store']);
    Route::get('assignments/{assignment}', [AssignmentAdminController::class, 'show']);
    Route::put('assignments/{assignment}', [AssignmentAdminController::class, 'update']);
    Route::delete('assignments/{assignment}', [AssignmentAdminController::class, 'destroy']);
    Route::post('assignments/{assignment}/publish', [AssignmentAdminController::class, 'publish']);
    Route::post('assignments/{assignment}/unpublish', [AssignmentAdminController::class, 'unpublish']);
    Route::put('assignments/{assignment}/rubric', [AssignmentAdminController::class, 'rubric']);

    Route::get('assignments/{assignment}/submissions', [SubmissionReviewController::class, 'index']);
    Route::get('submissions/{submission}', [SubmissionReviewController::class, 'show']);
    Route::post('submissions/{submission}/grade', [SubmissionReviewController::class, 'grade']);
    Route::post('submissions/{submission}/request-changes', [SubmissionReviewController::class, 'requestChanges']);
    Route::post('submissions/{submission}/release', [SubmissionReviewController::class, 'release']);
    Route::post('submissions/{submission}/unrelease', [SubmissionReviewController::class, 'unrelease']);

    Route::get('courses/{course}/gradebook', [GradebookController::class, 'show']);
    Route::get('courses/{course}/gradebook/export', [GradebookController::class, 'export']);
});

// ── Learner ─────────────────────────────────────────────────────────────────
Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    Route::get('assignments/{assignment}', [LearnerAssignmentController::class, 'show']);
    Route::get('assignments/{assignment}/submissions', [LearnerAssignmentController::class, 'history']);

    Route::post('assignments/{assignment}/draft', [SubmissionController::class, 'saveDraft']);
    Route::post('assignments/{assignment}/draft/files', [SubmissionController::class, 'attachFile']);
    Route::delete('submissions/{submission}/files/{file}', [SubmissionController::class, 'detachFile']);
    Route::post('assignments/{assignment}/submit', [SubmissionController::class, 'submit']);
    Route::post('assignments/{assignment}/resubmit', [SubmissionController::class, 'resubmit']);
    Route::get('submissions/{submission}', [SubmissionController::class, 'show']);
});
