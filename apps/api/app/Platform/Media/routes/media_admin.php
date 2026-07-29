<?php

use App\Platform\Media\Http\Controllers\Api\V1\InstructorMediaController;
use App\Platform\Media\Http\Controllers\Api\V1\MediaAttachmentController;
use App\Platform\Media\Http\Controllers\Api\V1\MediaCaptionController;
use Illuminate\Support\Facades\Route;

/*
 | P2/W04 - Instructor media API (authenticated). Base 'api' prefix + these => /api/v1/media/*.
 | {media} binds a MediaAsset by public_id; {caption} binds a MediaCaption by public_id. Ownership /
 | course-manager authorization lives in MediaAssetPolicy (denied => 404, no existence leak).
 */
Route::prefix('v1/media')->middleware('auth:sanctum')->group(function (): void {
    // Library + ingestion
    Route::get('assets', [InstructorMediaController::class, 'index']);
    Route::post('assets', [InstructorMediaController::class, 'store']);              // create direct upload
    Route::get('assets/{media}', [InstructorMediaController::class, 'show']);        // status
    Route::get('assets/{media}/signed-url', [InstructorMediaController::class, 'signedUrl']); // grader/owner file access
    Route::post('assets/{media}/finalize', [InstructorMediaController::class, 'finalize']);
    Route::post('assets/{media}/retry', [InstructorMediaController::class, 'retry']);
    Route::delete('assets/{media}', [InstructorMediaController::class, 'destroy']);

    // Attachments to authoring/assessment content
    Route::post('assets/{media}/attachments', [MediaAttachmentController::class, 'store']);
    Route::delete('assets/{media}/attachments', [MediaAttachmentController::class, 'destroy']);

    // Captions
    Route::get('assets/{media}/captions', [MediaCaptionController::class, 'index']);
    Route::post('assets/{media}/captions', [MediaCaptionController::class, 'store']);
    Route::delete('assets/{media}/captions/{caption}', [MediaCaptionController::class, 'destroy']);
});
