<?php

declare(strict_types=1);

use App\Domains\Forum\Http\Controllers\Api\V1\ForumPostController;
use App\Domains\Forum\Http\Controllers\Api\V1\ForumThreadController;
use Illuminate\Support\Facades\Route;

/*
 | Course discussion forum. Mounted at /api/v1 by BaseDomainServiceProvider (=> /api/v1/...).
 |
 | Every route is authenticated. Participation (enrolled learner / course instructor / super_admin)
 | is enforced per-endpoint by ForumThreadPolicy. `{course}` is a plain string (course public_id)
 | resolved through CurriculumReadPort; `{thread}` / `{post}` bind by public_id — threads under the
 | Forum CourseTenantScope, so a cross-tenant thread 404s. Writes carry a tighter rate limit.
 */
Route::prefix('v1')
    ->middleware(['auth:sanctum', 'throttle:120,1'])
    ->group(function (): void {
        // Course-scoped listing + creation.
        Route::get('courses/{course}/forum/threads', [ForumThreadController::class, 'index']);
        Route::post('courses/{course}/forum/threads', [ForumThreadController::class, 'store'])
            ->middleware('throttle:30,1');

        // Thread read + author/instructor mutation.
        Route::get('forum/threads/{thread}', [ForumThreadController::class, 'show']);
        Route::patch('forum/threads/{thread}', [ForumThreadController::class, 'update']);
        Route::delete('forum/threads/{thread}', [ForumThreadController::class, 'destroy']);

        // Instructor moderation.
        Route::post('forum/threads/{thread}/pin', [ForumThreadController::class, 'pin']);
        Route::post('forum/threads/{thread}/lock', [ForumThreadController::class, 'lock']);
        Route::post('forum/threads/{thread}/solve', [ForumThreadController::class, 'solve']);

        // Reporting.
        Route::post('forum/threads/{thread}/report', [ForumThreadController::class, 'report'])
            ->middleware('throttle:30,1');

        // Posts (replies).
        Route::post('forum/threads/{thread}/posts', [ForumPostController::class, 'store'])
            ->middleware('throttle:60,1');
        Route::patch('forum/posts/{post}', [ForumPostController::class, 'update']);
        Route::delete('forum/posts/{post}', [ForumPostController::class, 'destroy']);
        Route::post('forum/posts/{post}/report', [ForumPostController::class, 'report'])
            ->middleware('throttle:30,1');
    });
