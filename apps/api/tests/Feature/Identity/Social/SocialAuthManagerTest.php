<?php

use App\Platform\Identity\SocialAuth\Exceptions\SocialProviderDisabledException;
use App\Platform\Identity\SocialAuth\Exceptions\SsoDisabledException;
use App\Platform\Identity\SocialAuth\Exceptions\UnknownSocialProviderException;
use App\Platform\Identity\SocialAuth\Providers\FakeSocialProvider;
use App\Platform\Identity\SocialAuth\SocialAuthManager;

function socialManager(): SocialAuthManager
{
    return app(SocialAuthManager::class);
}

it('resolves the fake provider when SSO and the provider are enabled', function () {
    config(['sso.enabled' => true, 'sso.providers.fake.enabled' => true]);

    expect(socialManager()->provider('fake'))->toBeInstanceOf(FakeSocialProvider::class);
});

it('fails closed when SSO is disabled entirely', function () {
    config(['sso.enabled' => false]);

    expect(fn () => socialManager()->provider('fake'))->toThrow(SsoDisabledException::class);
});

it('fails closed for an unknown provider', function () {
    config(['sso.enabled' => true]);

    expect(fn () => socialManager()->provider('nope'))->toThrow(UnknownSocialProviderException::class);
});

it('fails closed for a disabled provider', function () {
    config(['sso.enabled' => true, 'sso.providers.google.enabled' => false]);

    expect(fn () => socialManager()->provider('google'))->toThrow(SocialProviderDisabledException::class);
});

it('refuses the fake provider in production unless explicitly allowed', function () {
    config([
        'sso.enabled' => true,
        'sso.providers.fake.enabled' => true,
        'sso.allow_fake_provider' => false,
        'app.env' => 'production',
    ]);

    expect(fn () => socialManager()->provider('fake'))->toThrow(RuntimeException::class);
});

it('permits the fake provider in production when explicitly allowed', function () {
    config([
        'sso.enabled' => true,
        'sso.providers.fake.enabled' => true,
        'sso.allow_fake_provider' => true,
        'app.env' => 'production',
    ]);

    expect(socialManager()->provider('fake'))->toBeInstanceOf(FakeSocialProvider::class);
});
