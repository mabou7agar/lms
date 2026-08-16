<?php

declare(strict_types=1);

use App\Domains\Qna\Http\Controllers\Api\V1\AnswerController;
use App\Domains\Qna\Http\Controllers\Api\V1\InstructorQnaController;
use App\Domains\Qna\Http\Controllers\Api\V1\QuestionController;
use Illuminate\Support\Facades\Route;

/*
 | Course Q&A endpoints. Mounted at /api by BaseDomainServiceProvider, so these resolve under
 | /api/v1/*. Every route is authenticated; participation (enrollment or course instruction) and
 | ownership are enforced per-request by the controllers/policies, and tenant isolation is enforced
 | by CourseTenantScope on the bound models. Writes are throttled to blunt spam/abuse.
 */
Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    // Reads — participation-gated in the controllers.
    Route::get('courses/{course}/questions', [QuestionController::class, 'index']);
    Route::get('questions/{question}', [QuestionController::class, 'show']);

    // The instructor's own queue across every course they teach, with SLA metrics attached.
    Route::get('instructor/questions', [InstructorQnaController::class, 'index']);

    // Writes — additionally rate-limited.
    Route::middleware('throttle:60,1')->group(function (): void {
        Route::post('courses/{course}/questions', [QuestionController::class, 'store']);
        Route::patch('questions/{question}', [QuestionController::class, 'update']);
        Route::delete('questions/{question}', [QuestionController::class, 'destroy']);
        Route::post('questions/{question}/pin', [QuestionController::class, 'pin']);
        Route::delete('questions/{question}/pin', [QuestionController::class, 'unpin']);
        Route::post('questions/{question}/report', [QuestionController::class, 'report']);

        Route::post('questions/{question}/answers', [AnswerController::class, 'store']);
        Route::post('answers/{answer}/accept', [AnswerController::class, 'accept']);
        Route::patch('answers/{answer}', [AnswerController::class, 'update']);
        Route::delete('answers/{answer}', [AnswerController::class, 'destroy']);
        Route::post('answers/{answer}/report', [AnswerController::class, 'report']);

        // Course team: end a thread, and mark the course's authoritative answer. Both are distinct
        // from the asker accepting an answer, which stays on the accept route above.
        Route::post('questions/{question}/close', [QuestionController::class, 'close']);
        Route::delete('questions/{question}/close', [QuestionController::class, 'reopen']);
        Route::post('answers/{answer}/official', [AnswerController::class, 'markOfficial']);
        Route::delete('answers/{answer}/official', [AnswerController::class, 'unmarkOfficial']);
    });
});
