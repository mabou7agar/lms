<?php

use App\Platform\Identity\SocialAuth\Apple\AppleClientSecret;
use App\Platform\Identity\SocialAuth\Jwt\JwksClient;
use App\Platform\Identity\SocialAuth\Jwt\JwtVerifier;
use App\Platform\Identity\SocialAuth\OidcClaimsValidator;
use App\Platform\Identity\SocialAuth\Providers\AppleOidcProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Cache::flush());

it('exchanges via Apple: signs an ES256 client secret and verifies the RS256 id_token', function () {
    [$ecJwk, , $ecResource] = ssoEcKey();
    if ($ecJwk === null) {
        $this->markTestSkipped('EC key generation unavailable in this environment.');
    }

    $ecPem = '';
    openssl_pkey_export($ecResource, $ecPem);

    [$rsaJwk, $rsaSign] = ssoRsaKey();
    $idToken = $rsaSign([
        'iss' => 'https://appleid.apple.com', 'aud' => 'com.app.service', 'nonce' => 'n',
        'sub' => 'apple-1', 'email' => 'relay@privaterelay.appleid.com', 'email_verified' => true,
        'exp' => time() + 300, 'iat' => time() - 5,
    ]);

    Http::fake([
        'appleid.apple.com/auth/token' => Http::response(['id_token' => $idToken]),
        'appleid.apple.com/auth/keys' => Http::response(['keys' => [$rsaJwk]]),
    ]);

    $config = [
        'authorization_endpoint' => 'https://appleid.apple.com/auth/authorize',
        'token_endpoint' => 'https://appleid.apple.com/auth/token',
        'jwks_uri' => 'https://appleid.apple.com/auth/keys',
        'issuer' => 'https://appleid.apple.com',
        'client_id' => 'com.app.service',
        'team_id' => 'TEAMID', 'key_id' => 'KEYID', 'private_key' => base64_encode($ecPem),
        'scopes' => ['openid', 'email', 'name'],
    ];

    $provider = new AppleOidcProvider(
        'apple', $config, app(Factory::class), new JwtVerifier, app(JwksClient::class), new OidcClaimsValidator, new AppleClientSecret($config),
    );

    $identity = $provider->exchange('code', 'n', 'https://app.test/cb');

    expect($identity->provider)->toBe('apple')
        ->and($identity->subject)->toBe('apple-1')
        ->and($identity->email)->toBe('relay@privaterelay.appleid.com');

    // The token request carried a JWT client secret (three dot-separated segments), not a static one.
    Http::assertSent(fn ($r) => str_contains($r->url(), '/auth/token')
        && is_string($r['client_secret']) && substr_count($r['client_secret'], '.') === 2);
});
