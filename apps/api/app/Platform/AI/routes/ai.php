<?php

declare(strict_types=1);

use App\Platform\AI\Http\Controllers\CopilotController;
use App\Platform\AI\Http\Controllers\TutorController;
use Illuminate\Support\Facades\Route;

/*
 | AI feature endpoints. Mounted under the framework 'api' group by AiServiceProvider, so these
 | resolve at /api/v1/ai/*. Both require an authenticated Sanctum user; role/enrollment/ownership
 | scoping is enforced inside the controllers, and governance + quota inside the AI foundation.
 |
 |  - POST /ai/tutor    STUDENT AI TUTOR   — learner must be enrolled in the course (403 otherwise).
 |  - POST /ai/copilot  INSTRUCTOR COPILOT — instructor must own the course (404 otherwise).
 */
Route::prefix('v1/ai')->middleware('auth:sanctum')->group(function (): void {
    Route::post('tutor', [TutorController::class, 'ask']);
    Route::post('copilot', [CopilotController::class, 'assist']);
});
