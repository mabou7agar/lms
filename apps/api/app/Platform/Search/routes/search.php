<?php

declare(strict_types=1);

use App\Platform\Search\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

/*
 | Search endpoints. Mounted under the framework 'api' group by SearchServiceProvider, so these
 | resolve at /api/v1/*.
 |
 |  - /search            PUBLIC catalogue search (published, publicly-visible courses only), throttled.
 |  - /search/knowledge  AUTHENTICATED knowledge search (adds lesson text + accepted Q&A), tenant-scoped.
 */
Route::prefix('v1')->group(function (): void {
    Route::get('search', [SearchController::class, 'catalog'])
        ->middleware('throttle:public-read');

    Route::get('search/knowledge', [SearchController::class, 'knowledge'])
        ->middleware('auth:sanctum');
});
