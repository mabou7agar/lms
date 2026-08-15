<?php

use App\Platform\Blog\Http\Controllers\Api\V1\BlogController;
use Illuminate\Support\Facades\Route;

/*
 | Blog CMS endpoints. Base 'api' prefix + these => /api/v1/blog*.
 |  - GET /blog/posts                  public, read-only paginated list of published posts.
 |  - GET /blog/categories             public, read-only list of categories (ordered).
 |  - GET /blog/posts/{slug}/preview   authenticated + admin/super_admin (current draft in any status).
 |  - GET /blog/posts/{slug}           public, read-only full post (404 unless live).
 |
 | The literal `/blog/categories` and `/blog/posts/{slug}/preview` routes are declared BEFORE the
 | `/blog/posts/{slug}` catch-all so a slug never shadows them. {slug} is a plain string.
 */
Route::prefix('v1')->group(function (): void {
    // M9 — throttle the public reads; the admin preview keeps its auth:sanctum guard.
    Route::get('blog/posts', [BlogController::class, 'index'])->middleware('throttle:public-read');
    Route::get('blog/categories', [BlogController::class, 'categories'])->middleware('throttle:public-read');
    Route::get('blog/posts/{slug}/preview', [BlogController::class, 'preview'])
        ->middleware('auth:sanctum')
        ->where('slug', '[A-Za-z0-9\-_/]+');
    Route::get('blog/posts/{slug}', [BlogController::class, 'show'])
        ->middleware('throttle:public-read')
        ->where('slug', '[A-Za-z0-9\-_/]+');
});
