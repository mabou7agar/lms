<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Shared\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts an authenticated but unverified account to the small surface needed to verify or leave.
 * Public routes and verified accounts are unchanged. The Sanctum guard is resolved explicitly
 * because API-group middleware executes before each route's `auth:sanctum` middleware.
 */
final class RequireVerifiedEmail
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->routeRequiresAuthentication($request)) {
            return $next($request);
        }

        $user = $request->user('sanctum');

        if ($user === null || $user->getAttribute('email_verified_at') !== null || $this->isVerificationSurface($request)) {
            return $next($request);
        }

        return ApiResponse::error(
            'EMAIL_VERIFICATION_REQUIRED',
            'Verify your email address before accessing this resource.',
            [],
            403,
        );
    }

    private function routeRequiresAuthentication(Request $request): bool
    {
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if ($middleware === 'auth:sanctum' || str_starts_with($middleware, 'auth:sanctum,')) {
                return true;
            }
        }

        return false;
    }

    private function isVerificationSurface(Request $request): bool
    {
        return ($request->isMethod('GET') && $request->is('api/v1/profile'))
            || ($request->isMethod('POST') && $request->is(
                'api/v1/auth/verify-email',
                'api/v1/auth/logout',
            ));
    }
}
