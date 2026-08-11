<?php

namespace App\Domains\Crm\Http\Controllers\Api\V1\Enterprise;

use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Services\ManagerScope;
use App\Domains\Crm\Services\ManagerScopeResult;
use App\Platform\Identity\Contracts\Actor;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shared plumbing for the enterprise manager portal: resolve the authenticated actor, their manager
 * scope (always keyed off the resolved tenant organization), and the tenant Organization model. Every
 * enterprise controller extends this so authority resolution is identical and centralized.
 */
abstract class EnterpriseController extends Controller
{
    protected function actor(Request $request): Actor
    {
        $user = $request->user();

        if (! $user instanceof Actor) {
            throw new AccessDeniedHttpException('Manager access required.');
        }

        return $user;
    }

    protected function scope(Request $request): ManagerScopeResult
    {
        return app(ManagerScope::class)->forActor($this->actor($request));
    }

    /** The active tenant organization, or a 403 when the caller has no organization context. */
    protected function organization(Request $request): Organization
    {
        $scope = $this->scope($request);

        if ($scope->organizationId === 0) {
            throw new AccessDeniedHttpException('No organization context.');
        }

        $organization = Organization::find($scope->organizationId);

        if ($organization === null) {
            throw new NotFoundHttpException('Organization not found.');
        }

        return $organization;
    }
}
