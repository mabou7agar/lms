<?php

declare(strict_types=1);

use App\Domains\Authoring\Http\Controllers\Api\V1\Admin\CourseResourceAdminController;
use App\Domains\Authoring\Http\Controllers\Api\V1\CourseResourceController;
use Illuminate\Support\Facades\Route;

/*
 | Course + lesson files. Base 'api' prefix + these => /api/v1/*.
 |
 | Two audiences, two prefixes. The learner routes list what is attached and mint a short-lived
 | signed download URL, re-checking entitlement on every request. The authoring routes attach and
 | arrange files, gated by the same course-ownership rule as editing the curriculum.
 */
Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    // Learner side.
    Route::get('courses/{course}/resources', [CourseResourceController::class, 'index']);
    Route::get('lessons/{lesson}/resources', [CourseResourceController::class, 'forLesson']);
    // A download is a POST because it mints a credential and records that access was granted —
    // neither of which belongs behind a cacheable, prefetchable GET.
    Route::post('resources/{resource}/download', [CourseResourceController::class, 'download'])
        ->middleware('throttle:60,1');

    // Authoring side.
    Route::get('authoring/courses/{course}/resources', [CourseResourceAdminController::class, 'index']);
    Route::post('authoring/courses/{course}/resources', [CourseResourceAdminController::class, 'store']);
    Route::patch('authoring/resources/{resource}', [CourseResourceAdminController::class, 'update']);
    Route::delete('authoring/resources/{resource}', [CourseResourceAdminController::class, 'destroy']);
});
