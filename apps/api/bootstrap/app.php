<?php

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnforceApiTokenScope;
use App\Http\Middleware\ForceJsonForApi;
use App\Http\Middleware\RequireVerifiedEmail;
use App\Http\Middleware\SecurityHeaders;
use App\Platform\Features\Http\Middleware\EnsureFeatureEnabled;
use App\Platform\Media\Http\Controllers\PublicMediaController;
use App\Platform\Shared\Http\Middleware\ResolveTenant;
use App\Platform\Shared\Http\Middleware\SetLocale;
use App\Platform\Shared\Support\ApiResponse;
use App\Platform\Shared\Support\HttpRefusal;
use App\Platform\Shared\Support\MalformedIdentifier;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        // Public media delivery is registered here (not in routes/web.php) so it receives ONLY global
        // middleware — never the `web` group's session/cookie stack. The endpoint is stateless, public,
        // and immutably cacheable (fingerprinted ?v= URL): it must not read or write a session. Besides
        // being architecturally correct, this removes the per-request Redis session read+decrypt+write
        // (SESSION_ENCRYPT) that serialised the browser's concurrent thumbnail/avatar burst and made
        // FrankenPHP/Octane return 503 under load (the `api` group, which has no session, never 503s).
        // This is a plain bare-route registration, NOT a `->withoutMiddleware(...)` strip of a web route.
        then: static function (): void {
            Route::get('/media/public/{publicId}', PublicMediaController::class)->name('media.public.show');
        },
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

        // Confine scoped (developer) API keys to the developer surface. A non-`*` token presented as a
        // bearer anywhere outside /api/v1/developer/* is rejected 403, so a read-scoped key can never
        // exercise the owner's session privileges on first-party routes. Session/`*` tokens unaffected.
        $middleware->appendToGroup('api', EnforceApiTokenScope::class);

        // A newly registered account may authenticate only so it can read its profile, submit the
        // email OTP, or log out. Every business endpoint remains closed until verification.
        $middleware->appendToGroup('api', RequireVerifiedEmail::class);

        // Multi-tenancy (A2-S02): resolve the active tenant on the API surface. Applied to the
        // 'api' group only (NOT globally) — web/marketing and Filament panels activate the
        // 'tenant.resolve' alias per-panel/per-route when needed. Resolution is also lazy in
        // TenantContext, so this is an explicit early-population step; it changes no behavior
        // until a model opts into the BelongsToTenant trait.
        $middleware->appendToGroup('api', ResolveTenant::class);

        // Locale negotiation for the API surface only (after tenant resolution so the org-default
        // step can read the active tenant). The Filament admin panel resolves locale from the
        // authenticated admin user, never from Accept-Language, so it is intentionally NOT here.
        $middleware->appendToGroup('api', SetLocale::class);

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

        // An identifier the database cannot represent is a 404, not a server error.
        //
        // See MalformedIdentifier for why this is a net rather than a per-call-site guard, and for
        // how narrowly it is scoped. Registered BEFORE the HttpException handler because a
        // QueryException is not an HttpException and would otherwise fall through to a 500.
        $exceptions->render(function (QueryException $e, Request $request): ?JsonResponse {
            if (! MalformedIdentifier::causedBy($e)) {
                return null;
            }

            // Deliberately says nothing about the malformed value or the statement: this is the one
            // error path a stranger can trigger at will, so it must not become a schema oracle.
            return $request->is('api/*')
                ? ApiResponse::error(HttpRefusal::codeFor(404), 'Not found.', [], 404)
                : null;
        });

        // Every other API refusal also renders as the standard envelope.
        //
        // Domain exceptions already do this themselves. What did not were the framework's own HTTP
        // exceptions — a 403 from an authorization gate, a 404 from an implicit binding, a 429 from
        // the throttler — which Laravel renders as a bare `{"message": "..."}`. A client that wants
        // to branch on WHY a request was refused had nothing to branch on: no code, no correlation
        // id, and a different response shape from every other error on the same API. The status
        // stays exactly what the thrower chose, and the thrower's message is preserved, so no
        // existing client's status handling changes — they gain a code they did not have.
        //
        // Validation is untouched: ValidationException is not an HttpExceptionInterface, so it never
        // reaches here and keeps Laravel's `errors` bag, which the forms read field by field.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e->getStatusCode();
            $message = $e->getMessage();

            return ApiResponse::error(
                HttpRefusal::codeFor($status),
                $message !== '' ? $message : HttpRefusal::messageFor($status),
                [],
                $status,
            );
        });

        // Domain exceptions render themselves to the standard envelope; defaults handle the rest.
        // Error tracking is optional: only wire Sentry when the package is actually installed.
        // With no SENTRY_LARAVEL_DSN configured it is a no-op even when present.
        if (class_exists(Integration::class)) {
            Integration::handles($exceptions);
        }
    })
    ->create();
