<?php

declare(strict_types=1);

namespace App\Platform\Identity\SocialAuth\Providers;

use App\Platform\Identity\SocialAuth\Contracts\SocialIdentityProvider;
use App\Platform\Identity\SocialAuth\Data\SocialIdentity;
use App\Platform\Identity\SocialAuth\Exceptions\InvalidSocialClaimsException;
use App\Platform\Identity\SocialAuth\Jwt\JwksClient;
use App\Platform\Identity\SocialAuth\Jwt\JwtVerifier;
use App\Platform\Identity\SocialAuth\OidcClaimsValidator;
use Illuminate\Http\Client\Factory;

/**
 * Standard OpenID Connect authorization-code provider (Google, Microsoft, any compliant OIDC IdP).
 *
 * `authorizationUrl()` builds the consent URL. `exchange()` runs the server-side half: swap the code
 * for tokens at the token endpoint, then verify the returned id_token's SIGNATURE against the IdP's
 * live JWKS ({@see JwtVerifier}) and its CLAIMS — issuer, audience, nonce, expiry
 * ({@see OidcClaimsValidator}) — before trusting a single field. Only after both pass is the token
 * mapped to a normalised {@see SocialIdentity}.
 *
 * The token/JWKS HTTP calls go through the injected client, so the whole adapter is exercised in CI
 * with faked responses + a self-signed token; pointing it at the real IdP needs live client
 * credentials (LOCAL REQUIRED).
 */
class GenericOidcProvider implements SocialIdentityProvider
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly string $providerKey,
        protected readonly array $config,
        protected readonly Factory $http,
        protected readonly JwtVerifier $jwt,
        protected readonly JwksClient $jwks,
        protected readonly OidcClaimsValidator $claims,
    ) {}

    public function key(): string
    {
        return $this->providerKey;
    }

    public function authorizationUrl(string $state, string $nonce, string $redirectUri): string
    {
        return $this->str('authorization_endpoint').'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->str('client_id'),
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
            'nonce' => $nonce,
        ]);
    }

    public function exchange(string $code, string $nonce, string $redirectUri): SocialIdentity
    {
        $token = $this->http->asForm()->post($this->str('token_endpoint'), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->str('client_id'),
            'client_secret' => $this->clientSecret(),
        ])->throw()->json();

        $idToken = is_array($token) ? (string) ($token['id_token'] ?? '') : '';
        if ($idToken === '') {
            throw new InvalidSocialClaimsException('id_token');
        }

        // 1) Signature: the token was minted by the holder of the IdP's private key.
        $claims = $this->jwt->verify($idToken, $this->jwks->keys($this->str('jwks_uri')));

        // 2) Semantics: right issuer + audience, our nonce, still valid.
        $this->claims->validate($claims, $this->str('issuer'), $this->str('client_id'), $nonce);

        return $this->claims->toIdentity($this->providerKey, $claims);
    }

    /** The client secret sent to the token endpoint. Overridden by Apple (a signed ES256 JWT). */
    protected function clientSecret(): string
    {
        return $this->str('client_secret');
    }

    /**
     * @return array<int, string>
     */
    protected function scopes(): array
    {
        $scopes = $this->config['scopes'] ?? ['openid', 'email', 'profile'];

        return is_array($scopes) ? array_values(array_map('strval', $scopes)) : ['openid', 'email', 'profile'];
    }

    protected function str(string $key): string
    {
        return isset($this->config[$key]) ? (string) $this->config[$key] : '';
    }
}
