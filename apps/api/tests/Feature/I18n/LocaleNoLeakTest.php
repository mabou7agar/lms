<?php

use App\Platform\Shared\I18n\TranslationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * TranslationResolver is stateless: resolving one locale then another yields the correct value each
 * time with no leakage between calls, and resolving never mutates the application locale. This
 * guards against a resolver that caches the first locale it sees or sets the app locale as a side
 * effect (either would silently serve the wrong language under concurrency).
 */
beforeEach(function () {
    config([
        'shared.locales' => ['en', 'ar'],
        'shared.fallback_locale' => 'en',
        'shared.default_locale' => 'en',
    ]);
});

/** @return array<string, string> */
function bilingualMap(): array
{
    return ['en' => 'English Value', 'ar' => 'القيمة العربية'];
}

it('resolves ar then en from the same map with no leakage between calls', function () {
    $resolver = app(TranslationResolver::class);
    $map = bilingualMap();

    // The ar resolution must not contaminate the subsequent en resolution (or vice-versa).
    expect($resolver->resolve($map, 'ar'))->toBe('القيمة العربية')
        ->and($resolver->resolve($map, 'en'))->toBe('English Value')
        ->and($resolver->resolve($map, 'ar'))->toBe('القيمة العربية');
});

it('does not mutate the application locale while resolving an explicit locale', function () {
    app()->setLocale('en');
    $resolver = app(TranslationResolver::class);

    $resolver->resolve(bilingualMap(), 'ar');

    expect(app()->getLocale())->toBe('en');
});

it('resolves via the active app locale independently across switches', function () {
    $resolver = app(TranslationResolver::class);
    $map = bilingualMap();

    app()->setLocale('ar');
    expect($resolver->resolve($map))->toBe('القيمة العربية');

    app()->setLocale('en');
    expect($resolver->resolve($map))->toBe('English Value');

    // Back to ar — a stateful resolver would have latched en from the previous call.
    app()->setLocale('ar');
    expect($resolver->resolve($map))->toBe('القيمة العربية');
});

it('is stateless across fresh resolver instances', function () {
    $map = bilingualMap();

    expect((new TranslationResolver)->resolve($map, 'ar'))->toBe('القيمة العربية')
        ->and((new TranslationResolver)->resolve($map, 'en'))->toBe('English Value');
});
