<?php

declare(strict_types=1);

namespace App\Platform\Shared\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the Filament admin panel's locale from the authenticated admin user's own `locale`
 * preference — never Accept-Language or the public API query (that is the api-group SetLocale's
 * job). Requests without an authenticated user (e.g. the login screen) fall through untouched on
 * the application default locale.
 */
final class SetAdminLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        $locale = $user instanceof Model ? $user->getAttribute('locale') : null;

        if (is_string($locale) && $locale !== '') {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
