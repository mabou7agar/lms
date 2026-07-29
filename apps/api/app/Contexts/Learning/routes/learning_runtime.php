<?php

use App\Contexts\Learning\Http\Controllers\Api\V1\BlockProgressController;
use App\Contexts\Learning\Http\Controllers\Api\V1\CourseLaunchController;
use App\Contexts\Learning\Http\Controllers\Api\V1\LessonCompletionController;
use App\Contexts\Learning\Http\Controllers\Api\V1\VideoProgressController;
use Illuminate\Support\Facades\Route;

/*
 | Learner RUNTIME endpoints (authenticated). Base 'api' prefix + these => /api/v1/*.
 | Additive to routes/learning.php; nothing here overrides an existing route.
 |
 | INTEGRATOR: register this file by adding 'routes/learning_runtime.php' to
 | LearningServiceProvider::$routeFiles.
 */
Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    // Course runtime
    Route::post('courses/{course}/launch', [CourseLaunchController::class, 'launch']);
    Route::get('courses/{course}/curriculum', [CourseLaunchController::class, 'curriculum']);
    Route::get('courses/{course}/resume', [CourseLaunchController::class, 'resume']);
    Route::get('courses/{course}/progress-summary', [CourseLaunchController::class, 'summary']);

    // Lesson runtime progress
    Route::post('lessons/{lesson}/viewed', [LessonCompletionController::class, 'viewed']);
    Route::post('lessons/{lesson}/complete', [LessonCompletionController::class, 'complete']);
    Route::post('lessons/{lesson}/video-progress', [VideoProgressController::class, 'store']);
    Route::post('lessons/{lesson}/blocks/{block}/complete', [BlockProgressController::class, 'store']);
});
