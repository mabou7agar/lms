<?php

use App\Domains\Crm\Models\Organization;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\I18n\LocaleResolver;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['shared.locales' => ['en', 'ar'], 'shared.fallback_locale' => 'en']);
    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

function resolveLocaleFor(Request $request): string
{
    return app(LocaleResolver::class)->resolve($request);
}

it('prefers the authenticated user locale over everything else', function () {
    $user = User::factory()->create(['locale' => 'ar']);
    $request = Request::create('/api/v1/x?locale=en', 'GET');
    $request->headers->set('Accept-Language', 'en');
    $request->setUserResolver(fn () => $user);

    expect(resolveLocaleFor($request))->toBe('ar');
});

it('uses an explicit query locale when there is no user', function () {
    expect(resolveLocaleFor(Request::create('/api/v1/x?locale=ar', 'GET')))->toBe('ar');
});

it('uses Accept-Language when there is no user or query locale', function () {
    $request = Request::create('/api/v1/x', 'GET');
    $request->headers->set('Accept-Language', 'ar');

    expect(resolveLocaleFor($request))->toBe('ar');
});

it('uses the organization default for the active tenant when nothing else is present', function () {
    $org = Organization::factory()->create(['locale' => 'ar']);
    app(TenantContext::class)->set(TenantId::from($org->id));

    // Request::create() injects a default Accept-Language; remove it so no explicit request signal
    // is present and the organization default (rank 3) is what resolves.
    $request = Request::create('/api/v1/x', 'GET');
    $request->headers->remove('Accept-Language');

    expect(resolveLocaleFor($request))->toBe('ar');
});

it('falls back to the application locale when no signal is present', function () {
    $request = Request::create('/api/v1/x', 'GET');
    $request->headers->remove('Accept-Language');

    expect(resolveLocaleFor($request))->toBe('en');
});

it('safely normalises an unsupported explicit locale by falling through', function () {
    expect(resolveLocaleFor(Request::create('/api/v1/x?locale=fr', 'GET')))->toBe('en');
});

it('does not leak one organization locale to a different tenant', function () {
    Organization::factory()->create(['locale' => 'ar']);
    $other = Organization::factory()->create(['locale' => null]);
    app(TenantContext::class)->set(TenantId::from($other->id));

    $request = Request::create('/api/v1/x', 'GET');
    $request->headers->remove('Accept-Language');

    // The tenant with no locale must fall back — never inherit the other org's Arabic.
    expect(resolveLocaleFor($request))->toBe('en');
});
