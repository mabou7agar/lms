<?php

use App\Platform\Identity\SocialAuth\Providers\AppleOidcProvider;
use App\Platform\Identity\SocialAuth\Providers\GenericOidcProvider;
use App\Platform\Identity\SocialAuth\SocialAuthManager;

it('resolves the OIDC driver for an enabled provider', function () {
    config(['sso.enabled' => true, 'sso.providers.google.enabled' => true]);

    expect(app(SocialAuthManager::class)->provider('google'))->toBeInstanceOf(GenericOidcProvider::class);
});

it('resolves the Apple driver for an enabled provider', function () {
    config(['sso.enabled' => true, 'sso.providers.apple.enabled' => true]);

    expect(app(SocialAuthManager::class)->provider('apple'))->toBeInstanceOf(AppleOidcProvider::class);
});
