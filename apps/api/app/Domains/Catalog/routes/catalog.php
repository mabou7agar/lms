<?php

use App\Domains\Catalog\Http\Controllers\Api\V1\CategoryController;
use App\Domains\Catalog\Http\Controllers\Api\V1\CourseController;
use App\Domains\Catalog\Http\Controllers\Api\V1\InstructorController;
use App\Domains\Catalog\Http\Controllers\Api\V1\RecommendationController;
use App\Domains\Catalog\Http\Controllers\Api\V1\TrainerController;
use Illuminate\Support\Facades\Route;

/*
 | Public catalog endpoints (read-only, unauthenticated). Base 'api' prefix + these => /api/v1/*.
 */
// M9 — throttle the whole (public, unauthenticated) catalog surface.
Route::prefix('v1')->middleware('throttle:public-read')->group(function (): void {
    Route::get('courses', [CourseController::class, 'index']);
    Route::get('courses/{publicId}', [CourseController::class, 'show']);
    Route::get('courses/{publicId}/recommendations', [RecommendationController::class, 'similar']);
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{publicId}/popular', [RecommendationController::class, 'popularInCategory']);
    Route::get('trainers', [TrainerController::class, 'index']);
    Route::get('instructors', [InstructorController::class, 'index']);
});
