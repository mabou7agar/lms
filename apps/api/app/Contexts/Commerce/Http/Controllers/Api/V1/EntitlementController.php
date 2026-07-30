<?php

namespace App\Contexts\Commerce\Http\Controllers\Api\V1;

use App\Contexts\Commerce\Actions\Entitlement\ListEntitlementsAction;
use App\Contexts\Commerce\Http\Resources\EntitlementResource;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;

/**
 * Read endpoint for the authenticated user's course entitlements. Thin: resolve the current user,
 * delegate to a read-side Action (which reads the Shared EntitlementPort), and shape the resulting
 * course ids through EntitlementResource. No persistence, no business logic here.
 */
class EntitlementController extends Controller
{
    public function index(Request $request, ListEntitlementsAction $action): JsonResponse
    {
        $courseIds = $action->execute($this->userId($request));

        return ApiResponse::success(
            EntitlementResource::collection(new Collection($courseIds)),
        );
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()->getAuthIdentifier();
    }
}
