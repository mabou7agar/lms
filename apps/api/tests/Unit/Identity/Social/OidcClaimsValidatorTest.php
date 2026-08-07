<?php

use App\Platform\Identity\SocialAuth\Exceptions\InvalidSocialClaimsException;
use App\Platform\Identity\SocialAuth\OidcClaimsValidator;

function oidcClaims(array $overrides = []): array
{
    return array_merge([
        'iss' => 'https://issuer.test',
        'aud' => 'client-123',
        'nonce' => 'nonce-1',
        'sub' => 'user-1',
        'exp' => time() + 300,
        'iat' => time() - 10,
        'email' => 'a@b.test',
        'email_verified' => true,
        'name' => 'Aya',
    ], $overrides);
}

it('accepts a well-formed token', function () {
    $v = new OidcClaimsValidator;

    expect(fn () => $v->validate(oidcClaims(), 'https://issuer.test', 'client-123', 'nonce-1'))
        ->not->toThrow(InvalidSocialClaimsException::class);
});

it('rejects a wrong issuer', function () {
    $v = new OidcClaimsValidator;

    expect(fn () => $v->validate(oidcClaims(['iss' => 'https://evil.test']), 'https://issuer.test', 'client-123', 'nonce-1'))
        ->toThrow(InvalidSocialClaimsException::class);
});

it('rejects a wrong audience', function () {
    $v = new OidcClaimsValidator;

    expect(fn () => $v->validate(oidcClaims(['aud' => 'someone-else']), 'https://issuer.test', 'client-123', 'nonce-1'))
        ->toThrow(InvalidSocialClaimsException::class);
});

it('accepts an audience array that contains our client id', function () {
    $v = new OidcClaimsValidator;

    expect(fn () => $v->validate(oidcClaims(['aud' => ['other', 'client-123']]), 'https://issuer.test', 'client-123', 'nonce-1'))
        ->not->toThrow(InvalidSocialClaimsException::class);
});

it('rejects a mismatched nonce', function () {
    $v = new OidcClaimsValidator;

    expect(fn () => $v->validate(oidcClaims(['nonce' => 'other']), 'https://issuer.test', 'client-123', 'nonce-1'))
        ->toThrow(InvalidSocialClaimsException::class);
});

it('rejects an expired token beyond leeway', function () {
    $v = new OidcClaimsValidator(leeway: 60);

    expect(fn () => $v->validate(oidcClaims(['exp' => time() - 3600]), 'https://issuer.test', 'client-123', 'nonce-1'))
        ->toThrow(InvalidSocialClaimsException::class);
});

it('rejects an issued-in-the-future token beyond leeway', function () {
    $v = new OidcClaimsValidator(leeway: 60);

    expect(fn () => $v->validate(oidcClaims(['iat' => time() + 3600]), 'https://issuer.test', 'client-123', 'nonce-1'))
        ->toThrow(InvalidSocialClaimsException::class);
});

it('rejects a missing subject', function () {
    $v = new OidcClaimsValidator;

    expect(fn () => $v->validate(oidcClaims(['sub' => '']), 'https://issuer.test', 'client-123', 'nonce-1'))
        ->toThrow(InvalidSocialClaimsException::class);
});

it('skips the nonce check when none is expected', function () {
    $v = new OidcClaimsValidator;

    expect(fn () => $v->validate(oidcClaims(['nonce' => 'whatever']), 'https://issuer.test', 'client-123', null))
        ->not->toThrow(InvalidSocialClaimsException::class);
});

it('maps validated claims onto a normalised identity', function () {
    $identity = (new OidcClaimsValidator)->toIdentity('google', oidcClaims());

    expect($identity->provider)->toBe('google')
        ->and($identity->subject)->toBe('user-1')
        ->and($identity->email)->toBe('a@b.test')
        ->and($identity->emailVerified)->toBeTrue()
        ->and($identity->name)->toBe('Aya');
});
