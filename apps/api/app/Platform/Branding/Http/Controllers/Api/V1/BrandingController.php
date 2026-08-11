<?php

namespace App\Platform\Branding\Http\Controllers\Api\V1;

use App\Platform\Branding\Services\OrganizationBrandingService;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Public branding endpoint. Read-only, unauthenticated, cacheable — returns the defaults-merged
 * public payload so the frontend can white-label the whole site (theme CSS variables, brand name,
 * logos, copyright, social links, metadata). Presentation only.
 *
 * White-label resolution: the payload is resolved by the request Host. A VERIFIED custom domain
 * yields its organization's merged brand; an unknown or unverified host falls back to the GLOBAL
 * brand exactly as before — the payload SHAPE is always the same, only the VALUES reflect the
 * resolved brand.
 */
class BrandingController extends Controller
{
    public function __construct(private readonly OrganizationBrandingService $branding) {}

    /** GET /api/v1/branding — the full, defaults-merged branding payload for the resolved Host. */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->branding->resolveByHost($request->getHost()),
        );
    }
}
