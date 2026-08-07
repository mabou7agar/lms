<?php

declare(strict_types=1);

namespace App\Platform\Identity\SocialAuth;

use App\Platform\Identity\SocialAuth\Contracts\SocialIdentityProvider;
use App\Platform\Identity\SocialAuth\Exceptions\SocialProviderDisabledException;
use App\Platform\Identity\SocialAuth\Exceptions\SsoDisabledException;
use App\Platform\Identity\SocialAuth\Exceptions\UnknownSocialProviderException;
use App\Platform\Identity\SocialAuth\Exceptions\UnsupportedSocialDriverException;
use App\Platform\Identity\SocialAuth\Providers\FakeSocialProvider;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * Resolves the social/SSO provider adapter for a given key from config('sso.*'), mirroring the
 * Commerce GatewayManager's "real-by-config, fail-closed" contract:
 *   - SSO off entirely, an unknown key, or a disabled provider each fail closed with a clear error;
 *   - every adapter is built uniformly with its config block (and the shared HTTP client for the
 *     network-bound OIDC/SAML drivers added in the LOCAL-REQUIRED increment);
 *   - the fake provider is refused in production unless SSO_ALLOW_FAKE_PROVIDER is set, so a
 *     misconfigured deploy can never accept unauthenticated "logins".
 *
 * The real OIDC/Apple/SAML drivers plug into the `default` arm; until they land, enabling one of
 * those providers fails closed rather than silently degrading.
 */
class SocialAuthManager
{
    public function __construct(
        private readonly Application $app,
    ) {}

    public function provider(string $key): SocialIdentityProvider
    {
        if ((bool) config('sso.enabled', false) !== true) {
            throw new SsoDisabledException;
        }

        $config = config('sso.providers.'.$key);
        if (! is_array($config)) {
            throw new UnknownSocialProviderException($key);
        }

        if ((bool) ($config['enabled'] ?? false) !== true) {
            throw new SocialProviderDisabledException($key);
        }

        $driver = (string) ($config['driver'] ?? $key);

        if ($driver === 'fake'
            && $this->app->make('config')->get('app.env') === 'production'
            && (bool) config('sso.allow_fake_provider', false) !== true) {
            throw new RuntimeException('The fake SSO provider is not permitted in production.');
        }

        return match ($driver) {
            'fake' => new FakeSocialProvider($config),
            // 'oidc' / 'apple' / 'saml' adapters are added in the LOCAL-REQUIRED increment and are
            // constructed here with the shared HTTP client + $config. Until then, fail closed.
            default => throw new UnsupportedSocialDriverException($driver),
        };
    }
}
