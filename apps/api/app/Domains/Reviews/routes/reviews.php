<?php

use App\Domains\Reviews\Http\Controllers\Api\V1\CourseReviewController;
use Illuminate\Support\Facades\Route;

/*
 | Course review endpoints. Base 'api' prefix + these => /api/v1/*.
 |
 | The index is public (read-only); every write requires auth:sanctum and is rate-limited. The
 | {review} parameter binds a CourseReview by public_id, whose global tenant scope makes another
 | tenant's review a clean 404.
 */
Route::prefix('v1')->group(function (): void {
    // Public read (reuse the catalog's public-read limiter).
    Route::get('courses/{course}/reviews', [CourseReviewController::class, 'index'])
        ->middleware('throttle:public-read');

    // Authenticated writes (throttled).
    Route::middleware(['auth:sanctum', 'throttle:30,1'])->group(function (): void {
        Route::post('courses/{course}/reviews', [CourseReviewController::class, 'store']);
        Route::patch('reviews/{review}', [CourseReviewController::class, 'update']);
        Route::delete('reviews/{review}', [CourseReviewController::class, 'destroy']);
        Route::post('reviews/{review}/helpful', [CourseReviewController::class, 'helpful']);
        Route::post('reviews/{review}/report', [CourseReviewController::class, 'report']);
        Route::post('reviews/{review}/respond', [CourseReviewController::class, 'respond']);
    });
});
