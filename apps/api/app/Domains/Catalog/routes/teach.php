<?php

use App\Domains\Catalog\Http\Controllers\Api\V1\Instructor\AnnouncementController;
use App\Domains\Catalog\Http\Controllers\Api\V1\Instructor\CourseController;
use App\Domains\Catalog\Http\Controllers\Api\V1\Instructor\DashboardController;
use App\Domains\Catalog\Http\Controllers\Api\V1\Instructor\StudentController;
use Illuminate\Support\Facades\Route;

/*
 | Instructor Portal endpoints (authenticated + role/ownership scoped in the controllers).
 | Base 'api' prefix + these => /api/v1/teach/*. Courses bind by public_id and are 404 for a
 | user who does not train them.
 */
Route::prefix('v1/teach')->middleware('auth:sanctum')->group(function (): void {
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('dashboard/overview', [DashboardController::class, 'overview']);
    Route::get('dashboard/courses', [DashboardController::class, 'performance']);
    Route::get('dashboard/activity', [DashboardController::class, 'activity']);
    Route::get('dashboard/alerts', [DashboardController::class, 'alerts']);

    Route::get('courses', [CourseController::class, 'index']);
    Route::get('courses/{course}', [CourseController::class, 'show']);
    Route::get('courses/{course}/readiness', [CourseController::class, 'readiness']);
    Route::get('courses/{course}/changes', [CourseController::class, 'changes']);
    Route::post('courses/{course}/publish', [CourseController::class, 'publish']);
    Route::post('courses/{course}/unpublish', [CourseController::class, 'unpublish']);
    Route::post('courses/{course}/archive', [CourseController::class, 'archive']);

    Route::get('courses/{course}/students', [StudentController::class, 'index']);

    Route::get('courses/{course}/announcements', [AnnouncementController::class, 'index']);
    Route::post('courses/{course}/announcements', [AnnouncementController::class, 'store']);
});
