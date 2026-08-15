<?php

namespace App\Platform\Blog\Http\Controllers\Api\V1;

use App\Platform\Blog\Http\Resources\BlogCategoryResource;
use App\Platform\Blog\Http\Resources\BlogPostListResource;
use App\Platform\Blog\Http\Resources\BlogPostResource;
use App\Platform\Blog\Models\BlogCategory;
use App\Platform\Blog\Models\BlogPost;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public blog delivery (read-only) plus an admin-only draft preview. Only posts that are published
 * AND inside their schedule window are exposed publicly; anything else 404s. The preview endpoint
 * returns the current draft regardless of status for authenticated admins.
 */
class BlogController extends Controller
{
    /**
     * GET /api/v1/blog/posts — paginated published posts (summary). Optional filters:
     *  - ?category={slug}  restrict to a category
     *  - ?featured=true    only featured posts
     * per_page defaults to 9 and is capped at 24.
     */
    public function index(Request $request, UserLookupPort $users): JsonResponse
    {
        $perPage = min(24, max(1, (int) $request->integer('per_page', 9)));

        $query = BlogPost::query()
            ->published()
            ->with(['category'])
            ->latest('published_at');

        $category = $request->query('category');

        if (is_string($category) && $category !== '') {
            $query->whereHas('category', fn ($q) => $q->where('slug', $category));
        }

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        $paginator = $query->paginate($perPage);

        // Resolve every author to a boundary-safe UserRef in ONE batched port call across the whole
        // page (no N+1); BlogPostListResource reads the stashed ref. Mirrors CourseController.
        $this->attachAuthorRefs($paginator->getCollection(), $users);

        return ApiResponse::paginated($paginator, BlogPostListResource::class);
    }

    /** GET /api/v1/blog/posts/{slug} — the full published post, or 404 when not live. */
    public function show(string $slug, UserLookupPort $users): JsonResponse
    {
        $post = BlogPost::query()
            ->published()
            ->with(['category'])
            ->where('slug', $slug)
            ->first();

        if ($post === null) {
            throw new NotFoundHttpException('Post not found.');
        }

        $this->resolveAuthorRef($post, $users);

        return ApiResponse::success((new BlogPostResource($post))->resolve());
    }

    /** GET /api/v1/blog/categories — categories ordered by position. */
    public function categories(): JsonResponse
    {
        $categories = BlogCategory::query()->orderBy('position')->orderBy('slug')->get();

        return ApiResponse::success([
            'categories' => $categories
                ->map(fn (BlogCategory $c) => (new BlogCategoryResource($c))->resolve())
                ->values(),
        ]);
    }

    /** GET /api/v1/blog/posts/{slug}/preview — admin-only; returns the current draft in any status. */
    public function preview(Request $request, string $slug, UserLookupPort $users): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof Actor || ! $user->hasRole(['admin', 'super_admin'])) {
            throw new AccessDeniedHttpException('Admin access required.');
        }

        $post = BlogPost::query()->with(['category'])->where('slug', $slug)->first();

        if ($post === null) {
            throw new NotFoundHttpException('Post not found.');
        }

        $this->resolveAuthorRef($post, $users);

        return ApiResponse::success((new BlogPostResource($post))->resolve());
    }

    /**
     * Batch-resolve each post's author_id to a boundary-safe UserRef in a single port call and stash
     * it on the post as `author_ref` (read by the list/detail resources). No N+1, no User model.
     *
     * @param  \Illuminate\Support\Collection<int, BlogPost>  $posts
     */
    private function attachAuthorRefs(Collection $posts, UserLookupPort $users): void
    {
        if ($posts->isEmpty()) {
            return;
        }

        $ids = $posts
            ->pluck('author_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $refs = $ids === [] ? [] : $users->refsByIds($ids); // [userId => UserRef], batched.

        foreach ($posts as $post) {
            $authorId = $post->getAttribute('author_id');
            $post->setAttribute('author_ref', $authorId !== null ? ($refs[(int) $authorId] ?? null) : null);
        }
    }

    /** Resolve a single post's author to a boundary-safe UserRef and stash it as `author_ref`. */
    private function resolveAuthorRef(BlogPost $post, UserLookupPort $users): void
    {
        $authorId = $post->author_id;

        if ($authorId === null) {
            $post->setAttribute('author_ref', null);

            return;
        }

        $post->setAttribute('author_ref', $users->refsByIds([(int) $authorId])[(int) $authorId] ?? null);
    }
}
