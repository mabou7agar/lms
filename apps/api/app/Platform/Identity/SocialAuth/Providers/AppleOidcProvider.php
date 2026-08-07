<?php

declare(strict_types=1);

namespace App\Platform\Identity\SocialAuth\Providers;

use App\Platform\Identity\SocialAuth\Apple\AppleClientSecret;
use App\Platform\Identity\SocialAuth\Jwt\JwksClient;
use App\Platform\Identity\SocialAuth\Jwt\JwtVerifier;
use App\Platform\Identity\SocialAuth\OidcClaimsValidator;
use Illuminate\Http\Client\Factory;

/**
 * "Sign in with Apple" — a standard OIDC authorization-code flow, except the token endpoint requires
 * a per-request ES256 JWT in place of a static client secret. Everything else (id_token signature +
 * claim verification) is inherited from {@see GenericOidcProvider}. Apple signs id_tokens with RS256,
 * so verification uses the RSA path against Apple's JWKS.
 */
final class AppleOidcProvider extends GenericOidcProvider
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        string $providerKey,
        array $config,
        Factory $http,
        JwtVerifier $jwt,
        JwksClient $jwks,
        OidcClaimsValidator $claims,
        private readonly AppleClientSecret $secret,
    ) {
        parent::__construct($providerKey, $config, $http, $jwt, $jwks, $claims);
    }

    protected function clientSecret(): string
    {
        return $this->secret->generate();
    }
}
