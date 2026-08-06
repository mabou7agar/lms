<?php

declare(strict_types=1);

namespace App\Platform\Shared\Http\Middleware;

use App\Platform\Shared\I18n\LocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request locale (see LocaleResolver) and applies it via app()->setLocale() for the
 * duration of the request. Registered on the `api` group ONLY — the Filament admin panel resolves
 * its locale from the authenticated admin user, never from Accept-Language.
 */
final class SetLocale
{
    public function __construct(private readonly LocaleResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolver->resolve($request));

        return $next($request);
    }
}
