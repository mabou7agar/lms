<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Shared\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confines SCOPED (developer) API keys to the developer surface.
 *
 * Sanctum's `auth:sanctum` guard authenticates ANY valid personal-access-token regardless of its
 * abilities; per-ability enforcement (`CheckAbilities`) exists only on the `/api/v1/developer/*`
 * routes. Without this middleware, a leaked/misused developer key — minted with only read scopes
 * (e.g. `account:read`) and issued exclusively to org-admins — would be a valid bearer token on
 * EVERY other `auth:sanctum` route (profile/devices/privacy/SSO-domains/webhook-management/…),
 * silently exercising the owner's full permissions and defeating the least-privilege guarantee the
 * scope system exists to provide.
 *
 * Rule: a token that does NOT carry the full-access `*` ability is a scoped developer key and may
 * only reach `api/v1/developer/*`. First-party login tokens carry `*` and are unaffected; session
 * (SPA cookie) requests have no bearer token and are skipped. The token is read directly from the
 * Authorization header (not the resolved guard user) so this is independent of auth middleware
 * ordering within the `api` group. Expiry/revocation are still enforced downstream by the guard.
 */
final class EnforceApiTokenScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer !== null) {
            $token = PersonalAccessToken::findToken($bearer);

            if ($token !== null
                && ! in_array('*', (array) $token->abilities, true)
                && ! $request->is('api/v1/developer/*', 'api/v1/api-keys', 'api/v1/api-keys/*')
            ) {
                return ApiResponse::error(
                    'TOKEN_SCOPE_FORBIDDEN',
                    'This API token is scoped to the developer surface and cannot access this endpoint.',
                    [],
                    403,
                );
            }
        }

        return $next($request);
    }
}
