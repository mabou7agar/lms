<?php

declare(strict_types=1);

namespace App\Platform\Search\Http\Controllers;

use App\Platform\Search\Data\VectorQuery;
use App\Platform\Search\Search\HybridSearchService;
use App\Platform\Search\Search\SearchHit;
use App\Platform\Shared\Search\Enums\SearchSourceType;
use App\Platform\Shared\Search\Enums\SearchVisibility;
use App\Platform\Shared\Support\ApiResponse;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Hybrid search API. Two surfaces with different audiences:
 *   - catalog()   — PUBLIC, unauthenticated: only published + publicly-visible COURSE content.
 *   - knowledge() — AUTHENTICATED: adds lesson text + accepted Q&A (visibility 'authenticated'),
 *                   still tenant-scoped, never private/other-tenant content.
 *
 * Tenant + visibility + locale are enforced by the VectorQuery pre-filter inside HybridSearchService,
 * so this controller only classifies the audience; it can never widen it.
 */
final class SearchController extends Controller
{
    public function __construct(
        private readonly HybridSearchService $search,
        private readonly TenantContext $tenant,
    ) {}

    /** Public catalogue search: courses only, public visibility. */
    public function catalog(Request $request): JsonResponse
    {
        $filters = new VectorQuery(
            organizationId: $this->currentOrganizationId(),
            visibilities: [SearchVisibility::Public->value],
            locales: $this->requestedLocales($request),
            sourceTypes: [SearchSourceType::Course->value],
        );

        return $this->respond($request, $filters);
    }

    /** Authenticated knowledge search: courses + lessons + accepted Q&A for signed-in users. */
    public function knowledge(Request $request): JsonResponse
    {
        $filters = new VectorQuery(
            organizationId: $this->currentOrganizationId(),
            visibilities: [SearchVisibility::Public->value, SearchVisibility::Authenticated->value],
            locales: $this->requestedLocales($request),
            sourceTypes: SearchSourceType::values(),
        );

        return $this->respond($request, $filters);
    }

    private function respond(Request $request, VectorQuery $filters): JsonResponse
    {
        $query = (string) $request->query('q', '');
        $limit = $request->has('limit') ? (int) $request->query('limit') : null;

        $hits = $this->search->search($query, $filters, $limit);

        return ApiResponse::success(
            array_map(static fn (SearchHit $hit): array => $hit->toArray(), $hits),
        );
    }

    /**
     * Locale is enforced ONLY when the caller explicitly scopes to one (?locale=xx). By default the
     * search is bilingual — it spans every language, mirroring the folded search_text index — so
     * Arabic content is never hidden just because the request locale happens to be English. The
     * language-agnostic '*' chunks are always additionally matched by the VectorQuery pre-filter.
     *
     * @return list<string>
     */
    private function requestedLocales(Request $request): array
    {
        $locale = $request->query('locale');

        return is_string($locale) && $locale !== '' ? [$locale] : [];
    }

    private function currentOrganizationId(): ?int
    {
        $id = $this->tenant->id();
        if ($id === null) {
            return null;
        }

        return is_numeric($id->value) ? (int) $id->value : null;
    }
}
