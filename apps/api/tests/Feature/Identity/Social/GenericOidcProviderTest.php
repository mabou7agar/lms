<?php

use App\Platform\Identity\SocialAuth\Exceptions\InvalidSocialClaimsException;
use App\Platform\Identity\SocialAuth\Jwt\JwksClient;
use App\Platform\Identity\SocialAuth\Jwt\JwtVerifier;
use App\Platform\Identity\SocialAuth\OidcClaimsValidator;
use App\Platform\Identity\SocialAuth\Providers\GenericOidcProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function oidcConfig(array $overrides = []): array
{
    return array_merge([
        'authorization_endpoint' => 'https://idp.test/authorize',
        'token_endpoint' => 'https://idp.test/token',
        'jwks_uri' => 'https://idp.test/jwks',
        'issuer' => 'https://idp.test',
        'client_id' => 'client-xyz',
        'client_secret' => 'shhh',
        'scopes' => ['openid', 'email', 'profile'],
    ], $overrides);
}

function oidcProvider(array $config): GenericOidcProvider
{
    return new GenericOidcProvider('google', $config, app(Factory::class), new JwtVerifier, app(JwksClient::class), new OidcClaimsValidator);
}

function oidcIdTokenClaims(array $overrides = []): array
{
    return array_merge([
        'iss' => 'https://idp.test', 'aud' => 'client-xyz', 'nonce' => 'nonce-1', 'sub' => 'sub-1',
        'email' => 'user@idp.test', 'email_verified' => true, 'name' => 'IdP User',
        'exp' => time() + 300, 'iat' => time() - 5,
    ], $overrides);
}

beforeEach(fn () => Cache::flush());

it('builds an authorization url with the required parameters', function () {
    $url = oidcProvider(oidcConfig())->authorizationUrl('state-1', 'nonce-1', 'https://app.test/cb');

    expect($url)->toContain('https://idp.test/authorize?')
        ->toContain('response_type=code')
        ->toContain('client_id=client-xyz')
        ->toContain('state=state-1')
        ->toContain('nonce=nonce-1');
});

it('exchanges an authorization code for a verified identity', function () {
    [$jwk, $sign] = ssoRsaKey();
    Http::fake([
        'idp.test/token' => Http::response(['id_token' => $sign(oidcIdTokenClaims()), 'access_token' => 'at']),
        'idp.test/jwks' => Http::response(['keys' => [$jwk]]),
    ]);

    $identity = oidcProvider(oidcConfig())->exchange('auth-code', 'nonce-1', 'https://app.test/cb');

    expect($identity->provider)->toBe('google')
        ->and($identity->subject)->toBe('sub-1')
        ->and($identity->email)->toBe('user@idp.test')
        ->and($identity->emailVerified)->toBeTrue();

    Http::assertSent(fn ($r) => str_contains($r->url(), '/token')
        && $r['grant_type'] === 'authorization_code' && $r['code'] === 'auth-code');
});

it('rejects an id_token with a bad signature', function () {
    [, $sign] = ssoRsaKey();
    [$otherJwk] = ssoRsaKey();
    Http::fake([
        'idp.test/token' => Http::response(['id_token' => $sign(oidcIdTokenClaims())]),
        'idp.test/jwks' => Http::response(['keys' => [$otherJwk]]),
    ]);

    expect(fn () => oidcProvider(oidcConfig())->exchange('c', 'nonce-1', 'https://app.test/cb'))
        ->toThrow(InvalidSocialClaimsException::class);
});

it('rejects an id_token whose nonce does not match', function () {
    [$jwk, $sign] = ssoRsaKey();
    Http::fake([
        'idp.test/token' => Http::response(['id_token' => $sign(oidcIdTokenClaims(['nonce' => 'different']))]),
        'idp.test/jwks' => Http::response(['keys' => [$jwk]]),
    ]);

    expect(fn () => oidcProvider(oidcConfig())->exchange('c', 'nonce-1', 'https://app.test/cb'))
        ->toThrow(InvalidSocialClaimsException::class);
});

it('rejects an id_token from the wrong issuer', function () {
    [$jwk, $sign] = ssoRsaKey();
    Http::fake([
        'idp.test/token' => Http::response(['id_token' => $sign(oidcIdTokenClaims(['iss' => 'https://evil.test']))]),
        'idp.test/jwks' => Http::response(['keys' => [$jwk]]),
    ]);

    expect(fn () => oidcProvider(oidcConfig())->exchange('c', 'nonce-1', 'https://app.test/cb'))
        ->toThrow(InvalidSocialClaimsException::class);
});

it('rejects a token response with no id_token', function () {
    Http::fake(['idp.test/token' => Http::response(['access_token' => 'at'])]);

    expect(fn () => oidcProvider(oidcConfig())->exchange('c', 'nonce-1', 'https://app.test/cb'))
        ->toThrow(InvalidSocialClaimsException::class);
});
