<?php

use App\Platform\Identity\SocialAuth\Exceptions\InvalidSocialClaimsException;
use App\Platform\Identity\SocialAuth\Jwt\JwtVerifier;

it('verifies an RS256 token and returns its claims', function () {
    [$jwk, $sign] = ssoRsaKey();

    $claims = (new JwtVerifier)->verify($sign(['sub' => 'u1', 'email' => 'a@b.test']), [$jwk]);

    expect($claims['sub'])->toBe('u1')->and($claims['email'])->toBe('a@b.test');
});

it('rejects an RS256 token whose payload was tampered', function () {
    [$jwk, $sign] = ssoRsaKey();
    [$head, , $sig] = explode('.', $sign(['sub' => 'u1']));
    $forged = $head.'.'.ssoB64u((string) json_encode(['sub' => 'attacker'])).'.'.$sig;

    expect(fn () => (new JwtVerifier)->verify($forged, [$jwk]))->toThrow(InvalidSocialClaimsException::class);
});

it('rejects a token signed by a different key', function () {
    [, $sign] = ssoRsaKey();
    [$otherJwk] = ssoRsaKey(); // same kid, different key material

    expect(fn () => (new JwtVerifier)->verify($sign(['sub' => 'u1']), [$otherJwk]))->toThrow(InvalidSocialClaimsException::class);
});

it('rejects a token whose kid is absent from the JWKS', function () {
    [$jwk, $sign] = ssoRsaKey('kid-A');
    $jwk['kid'] = 'kid-B';

    expect(fn () => (new JwtVerifier)->verify($sign(['sub' => 'u1']), [$jwk]))->toThrow(InvalidSocialClaimsException::class);
});

it('rejects an unsupported algorithm (e.g. alg none)', function () {
    $head = ssoB64u((string) json_encode(['alg' => 'none', 'typ' => 'JWT']));
    $body = ssoB64u((string) json_encode(['sub' => 'u1']));

    expect(fn () => (new JwtVerifier)->verify("{$head}.{$body}.", []))->toThrow(InvalidSocialClaimsException::class);
});

it('verifies an ES256 token', function () {
    [$jwk, $sign] = ssoEcKey();
    if ($jwk === null) {
        $this->markTestSkipped('EC key generation unavailable in this environment.');
    }

    expect((new JwtVerifier)->verify($sign(['sub' => 'ec1']), [$jwk])['sub'])->toBe('ec1');
});
