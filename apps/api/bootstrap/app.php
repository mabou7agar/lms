<?php

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\ForceJsonForApi;
use App\Http\Middleware\SecurityHeaders;
use App\Platform\Features\Http\Middleware\EnsureFeatureEnabled;
use App\Platform\Shared\Http\Middleware\ResolveTenant;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

/*
 | HElbaron API bootstrap.
 | REST only under /api/v1. Liveness: GET /up and /api/v1/health. Readiness: /api/v1/health/ready.
 | Global middleware: correlation id (early) + security headers (late). Trusted proxies/hosts
 | are enforced for correct HTTPS/host handling behind a load balancer.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind ALB/CloudFront: trust forwarded headers so isSecure()/host are correct.
        // Fail CLOSED by default — trusting all proxies ('*') when the env var is unset lets a
        // client spoof X-Forwarded-For and defeat every IP-keyed rate limiter. Trust nothing unless
        // an explicit proxy list (CIDRs, or an explicit literal '*') is configured.
        $trustedProxies = trim((string) env('TRUSTED_PROXIES', ''));
        $middleware->trustProxies(
            at: match (true) {
                $trustedProxies === '' => [],
                $trustedProxies === '*' => '*',
                default => explode(',', $trustedProxies),
            },
        );

        // Enforce Host allow-list in production only (avoids blocking local/test hosts).
        $middleware->trustHosts(at: static function (): array {
            $hosts = array_filter(array_map('trim', explode(',', (string) env('APP_TRUSTED_HOSTS', ''))));

            return $hosts === [] ? [] : $hosts;
        }, subdomains: true);

        $middleware->prepend(AssignCorrelationId::class);
        $middleware->append(SecurityHeaders::class);

        // API is JSON-only: normalize api/* requests to expect JSON so error handling always takes
        // the JSON path (prevents an unauthenticated request from attempting a redirect to a
        // non-existent `login` route -> RouteNotFoundException -> 500). Prepended so it runs before
        // auth. Scoped to the 'api' group; web/Filament are untouched.
        $middleware->prependToGroup('api', ForceJsonForApi::class);

        // Multi-tenancy (A2-S02): resolve the active tenant on the API surface. Applied to the
        // 'api' group only (NOT globally) — web/marketing and Filament panels activate the
        // 'tenant.resolve' alias per-panel/per-route when needed. Resolution is also lazy in
        // TenantContext, so this is an explicit early-population step; it changes no behavior
        // until a model opts into the BelongsToTenant trait.
        $middleware->appendToGroup('api', ResolveTenant::class);

        // Feature-flag route guard: `->middleware('feature:<key>')`. Additive — default-on flags
        // plus the built-in admin override mean a normal run is unaffected. See EnsureFeatureEnabled.
        $middleware->alias([
            'feature' => EnsureFeatureEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API is JSON-only: an unauthenticated api/* request must ALWAYS render the standard JSON 401
        // envelope, regardless of the Accept header. Without this, the framework's default handler
        // tries to redirect to a named `login` route (which this API-only app does not define) and
        // throws RouteNotFoundException -> HTTP 500 whenever Accept is not application/json. Scoped to
        // api/* so Filament/web auth (which does have a login route) is left untouched.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('UNAUTHENTICATED', 'Unauthenticated.', [], 401);
            }

            return null;
        });

        // Domain exceptions render themselves to the standard envelope; defaults handle the rest.
        // Error tracking is optional: only wire Sentry when the package is actually installed.
        // With no SENTRY_LARAVEL_DSN configured it is a no-op even when present.
        if (class_exists(Integration::class)) {
            Integration::handles($exceptions);
        }
    })
    ->create();
