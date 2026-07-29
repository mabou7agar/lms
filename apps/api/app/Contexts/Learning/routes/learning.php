<?php

use App\Contexts\Learning\Http\Controllers\Api\V1\BookmarkController;
use App\Contexts\Learning\Http\Controllers\Api\V1\ContinueLearningController;
use App\Contexts\Learning\Http\Controllers\Api\V1\EnrollmentController;
use App\Contexts\Learning\Http\Controllers\Api\V1\LearnController;
use App\Contexts\Learning\Http\Controllers\Api\V1\LessonPlaybackController;
use App\Contexts\Learning\Http\Controllers\Api\V1\LessonPlayerController;
use App\Contexts\Learning\Http\Controllers\Api\V1\LessonProgressController;
use App\Contexts\Learning\Http\Controllers\Api\V1\MyLearningController;
use App\Contexts\Learning\Http\Controllers\Api\V1\NoteController;
use Illuminate\Support\Facades\Route;

/*
 | Learner endpoints (authenticated). Base 'api' prefix + these => /api/v1/*.
 */
Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    Route::get('my-learning', [MyLearningController::class, 'index']);
    Route::get('continue-learning', [ContinueLearningController::class, 'index']);

    Route::post('courses/{course}/enroll', [EnrollmentController::class, 'store']);
    Route::get('courses/{course}/learn', [LearnController::class, 'show']);

    Route::get('lessons/{lesson}', [LessonPlayerController::class, 'show']);
    Route::get('lessons/{lesson}/playback', [LessonPlaybackController::class, 'show']);
    Route::post('lessons/{lesson}/progress', [LessonProgressController::class, 'store']);
    Route::post('lessons/{lesson}/bookmark', [BookmarkController::class, 'store']);
    Route::post('lessons/{lesson}/notes', [NoteController::class, 'store']);
});
