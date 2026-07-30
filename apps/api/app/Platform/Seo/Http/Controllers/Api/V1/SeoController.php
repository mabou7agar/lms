<?php

namespace App\Platform\Seo\Http\Controllers\Api\V1;

use App\Platform\Identity\Contracts\Actor;
use App\Platform\Seo\Enums\SeoEntityType;
use App\Platform\Seo\Http\Resources\SeoMetaResource;
use App\Platform\Seo\Models\SeoMeta;
use App\Platform\Seo\Services\SeoResolver;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The SEO Manager's HTTP surface:
 *  - GET /api/v1/seo/{entityType}/{key}  public; the single resolved SEO payload the frontend maps
 *    straight into Next.js Metadata (the frontend does NOT re-derive SEO logic).
 *  - GET /api/v1/seo/sitemap             public; sitemap-enabled, indexable entities with url +
 *    priority + changefreq (deduplicated) — consumed by the Next.js sitemap.
 *  - GET /api/v1/seo                       admin; the manager list of stored records + warnings.
 *
 * An unknown entityType 404s (validated against SeoEntityType).
 */
class SeoController extends Controller
{
    public function __construct(private readonly SeoResolver $resolver) {}

    /** GET /api/v1/seo/{entityType}/{key} — resolved SEO for one surface. */
    public function show(string $entityType, string $key): JsonResponse
    {
        $type = SeoEntityType::tryFrom($entityType);

        if ($type === null) {
            throw new NotFoundHttpException('Unknown SEO entity type.');
        }

        return ApiResponse::success($this->resolver->resolve($type, $key));
    }

    /**
     * GET /api/v1/seo/sitemap — the managed, indexable, sitemap-enabled entities. Deduplicated by
     * resolved URL so no duplicate <url> can be emitted. noindex or sitemap-disabled rows are
     * excluded here (their noindex is still honoured via the per-entity show endpoint).
     */
    public function sitemap(): JsonResponse
    {
        $seen = [];
        $entries = [];

        $rows = SeoMeta::query()
            ->where('sitemap_enabled', true)
            ->where('robots_index', true)
            ->get();

        foreach ($rows as $meta) {
            $url = is_string($meta->canonical) && $this->resolver->isValidCanonical($meta->canonical)
                ? trim($meta->canonical)
                : $meta->entity_type->path($meta->entity_key);

            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $entries[] = [
                'url' => $url,
                'priority' => $meta->sitemap_priority,
                'changefreq' => $meta->sitemap_changefreq,
                'updated_at' => $meta->updated_at?->toIso8601String(),
            ];
        }

        return ApiResponse::success(['entries' => $entries]);
    }

    /** GET /api/v1/seo — admin manager list of stored SEO records. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof Actor || ! $user->hasRole(['admin', 'super_admin'])) {
            throw new AccessDeniedHttpException('Admin access required.');
        }

        // Paginated: the SEO table grows with every course/page/entity, so it must not be loaded in
        // one unbounded query. The `records` envelope is preserved and pagination is exposed under
        // meta so the admin manager can page through.
        $paginator = SeoMeta::query()
            ->orderBy('entity_type')
            ->orderBy('entity_key')
            ->paginate(max(1, min((int) $request->integer('per_page', 50), 200)))
            ->withQueryString();

        return ApiResponse::success(
            ['records' => $paginator->getCollection()->map(fn (SeoMeta $m) => (new SeoMetaResource($m))->resolve())->values()],
            null,
            200,
            ['pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ]],
        );
    }
}
