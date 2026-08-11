<?php

namespace App\Domains\Crm\Http\Controllers\Api\V1\Enterprise;

use App\Domains\Crm\Actions\Organization\AcceptInvitationAction;
use App\Domains\Crm\Actions\Organization\DeclineInvitationAction;
use App\Domains\Crm\Http\Resources\OrganizationMemberResource;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Invitation lifecycle. These endpoints are token-authorized, NOT manager-gated: the bearer of a
 * valid single-use token may accept (linking THEIR authenticated account to the membership) or
 * decline it. The token itself is the authority, so a plain user can complete their own invitation.
 */
class InvitationController extends Controller
{
    public function accept(Request $request, string $token, AcceptInvitationAction $action): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof Actor) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        $member = $action->execute($token, $user->actorId());

        return ApiResponse::success(new OrganizationMemberResource($member), 'Invitation accepted.');
    }

    public function decline(Request $request, string $token, DeclineInvitationAction $action): JsonResponse
    {
        $action->execute($token);

        return ApiResponse::success(null, 'Invitation declined.');
    }
}
