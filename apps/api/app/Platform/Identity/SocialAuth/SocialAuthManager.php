<?php

declare(strict_types=1);

namespace App\Platform\Identity\SocialAuth;

use App\Platform\Identity\SocialAuth\Apple\AppleClientSecret;
use App\Platform\Identity\SocialAuth\Contracts\SocialIdentityProvider;
use App\Platform\Identity\SocialAuth\Exceptions\SocialProviderDisabledException;
use App\Platform\Identity\SocialAuth\Exceptions\SsoDisabledException;
use App\Platform\Identity\SocialAuth\Exceptions\UnknownSocialProviderException;
use App\Platform\Identity\SocialAuth\Exceptions\UnsupportedSocialDriverException;
use App\Platform\Identity\SocialAuth\Jwt\JwksClient;
use App\Platform\Identity\SocialAuth\Jwt\JwtVerifier;
use App\Platform\Identity\SocialAuth\Providers\AppleOidcProvider;
use App\Platform\Identity\SocialAuth\Providers\FakeSocialProvider;
use App\Platform\Identity\SocialAuth\Providers\GenericOidcProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use RuntimeException;

/**
 * Resolves the social/SSO provider adapter for a given key from config('sso.*'), mirroring the
 * Commerce GatewayManager's "real-by-config, fail-closed" contract:
 *   - SSO off entirely, an unknown key, or a disabled provider each fail closed with a clear error;
 *   - every adapter is built uniformly with its config block + the shared HTTP client;
 *   - the fake provider is refused in production unless SSO_ALLOW_FAKE_PROVIDER is set, so a
 *     misconfigured deploy can never accept unauthenticated "logins".
 *
 * Drivers: 'fake' (local/testing), 'oidc' (Google/Microsoft/any compliant IdP), 'apple' (OIDC with an
 * ES256 client-secret). A 'saml' driver is not wired: enterprise SAML needs XML-DSIG verification,
 * which fails closed here until that capability lands. Unknown drivers fail closed.
 */
class SocialAuthManager
{
    public function __construct(
        private readonly Application $app,
        private readonly Factory $http,
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
            'oidc' => new GenericOidcProvider(
                $key, $config, $this->http,
                $this->app->make(JwtVerifier::class),
                $this->app->make(JwksClient::class),
                $this->app->make(OidcClaimsValidator::class),
            ),
            'apple' => new AppleOidcProvider(
                $key, $config, $this->http,
                $this->app->make(JwtVerifier::class),
                $this->app->make(JwksClient::class),
                $this->app->make(OidcClaimsValidator::class),
                new AppleClientSecret($config),
            ),
            default => throw new UnsupportedSocialDriverException($driver),
        };
    }
}
