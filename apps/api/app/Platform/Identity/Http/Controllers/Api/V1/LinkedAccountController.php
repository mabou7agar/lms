<?php

namespace App\Platform\Identity\Http\Controllers\Api\V1;

use App\Platform\Identity\Actions\Social\UnlinkSocialAccountAction;
use App\Platform\Identity\Http\Resources\SocialAccountResource;
use App\Platform\Identity\Models\SocialAccount;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Authenticated management of the caller's own linked social/SSO accounts.
 *
 *  - index   lists the caller's linked providers (provider, email, linked_at — never tokens/secrets).
 *  - destroy unlinks one provider, refusing to remove the last remaining sign-in method (orphan-safe).
 */
class LinkedAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $accounts = $user->socialAccounts()->latest('id')->get();

        // `has_password` lets the UI disable/explain the unlink of the LAST sign-in method without
        // exposing any credential — a social-only account (no usable password) cannot drop its only
        // provider. The backend still enforces this authoritatively on unlink.
        return ApiResponse::success([
            'accounts' => SocialAccountResource::collection($accounts),
            'has_password' => $user->hasUsablePassword(),
        ]);
    }

    public function destroy(Request $request, SocialAccount $socialAccount, UnlinkSocialAccountAction $action): JsonResponse
    {
        Gate::authorize('delete', $socialAccount);

        $action->execute($request->user(), $socialAccount);

        return ApiResponse::deleted('Provider unlinked.');
    }
}
