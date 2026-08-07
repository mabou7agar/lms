<?php

use App\Domains\Authoring\Http\Controllers\Api\V1\Admin\CurriculumAdminController;
use App\Domains\Authoring\Http\Controllers\Api\V1\Admin\LessonAdminController;
use App\Domains\Authoring\Http\Controllers\Api\V1\Admin\SectionAdminController;
use Illuminate\Support\Facades\Route;

/*
 | Authoring admin API (authenticated). Base 'api' prefix + these => /api/v1/admin/*.
 | Route models bind by public_id (course, section, lesson).
 */
Route::prefix('v1/admin')->middleware('auth:sanctum')->group(function (): void {
    // Curriculum (whole-tree)
    Route::get('courses/{course}/curriculum', [CurriculumAdminController::class, 'show']);
    Route::put('courses/{course}/curriculum/order', [CurriculumAdminController::class, 'reorder']);

    // Sections
    Route::post('courses/{course}/sections', [SectionAdminController::class, 'store']);
    Route::put('courses/{course}/sections/order', [SectionAdminController::class, 'reorder']);
    Route::put('sections/{section}', [SectionAdminController::class, 'update']);
    Route::delete('sections/{section}', [SectionAdminController::class, 'destroy']);
    Route::post('sections/{section}/publish', [SectionAdminController::class, 'publish']);
    // Deep-copy a section and all its lessons; appended at the end of the course, Draft.
    Route::post('courses/{course}/sections/{section}/duplicate', [SectionAdminController::class, 'duplicate']);

    // Lessons
    Route::post('sections/{section}/lessons', [LessonAdminController::class, 'store']);
    Route::put('sections/{section}/lessons/order', [LessonAdminController::class, 'reorder']);
    // Deep-copy a lesson within its section; appended at the end of the section, Draft.
    Route::post('sections/{section}/lessons/{lesson}/duplicate', [LessonAdminController::class, 'duplicate']);
    Route::put('lessons/{lesson}', [LessonAdminController::class, 'update']);
    Route::delete('lessons/{lesson}', [LessonAdminController::class, 'destroy']);
    Route::post('lessons/{lesson}/publish', [LessonAdminController::class, 'publish']);
    Route::post('lessons/{lesson}/preview', [LessonAdminController::class, 'preview']);
    Route::put('lessons/{lesson}/prerequisites', [LessonAdminController::class, 'prerequisites']);
    Route::put('lessons/{lesson}/media', [LessonAdminController::class, 'media']);
    // Quiz lessons reference an Assessment; the body's assessment_id may be null to detach.
    Route::put('lessons/{lesson}/assessment', [LessonAdminController::class, 'assessment']);
});
