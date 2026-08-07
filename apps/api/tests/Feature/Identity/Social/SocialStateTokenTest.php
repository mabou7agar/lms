<?php

use App\Platform\Identity\SocialAuth\Exceptions\InvalidSocialStateException;
use App\Platform\Identity\SocialAuth\SocialStateToken;

it('round-trips the nonce and redirect uri', function () {
    $svc = new SocialStateToken;

    ['state' => $state, 'nonce' => $nonce] = $svc->issue('google', 'https://app.test/callback');
    $verified = $svc->verify($state, 'google');

    expect($verified['nonce'])->toBe($nonce)
        ->and($verified['redirect_uri'])->toBe('https://app.test/callback');
});

it('rejects a tampered signature', function () {
    $svc = new SocialStateToken;
    ['state' => $state] = $svc->issue('google', 'https://app.test/callback');

    [$body] = explode('.', $state);

    expect(fn () => $svc->verify($body.'.deadbeef', 'google'))->toThrow(InvalidSocialStateException::class);
});

it('rejects a malformed token', function () {
    expect(fn () => (new SocialStateToken)->verify('not-a-valid-token', 'google'))
        ->toThrow(InvalidSocialStateException::class);
});

it('rejects a state minted for a different provider', function () {
    $svc = new SocialStateToken;
    ['state' => $state] = $svc->issue('google', 'https://app.test/callback');

    expect(fn () => $svc->verify($state, 'microsoft'))->toThrow(InvalidSocialStateException::class);
});

it('rejects an expired state', function () {
    config(['sso.state_ttl' => -1]); // mint already-expired
    $svc = new SocialStateToken;
    ['state' => $state] = $svc->issue('google', 'https://app.test/callback');

    expect(fn () => $svc->verify($state, 'google'))->toThrow(InvalidSocialStateException::class);
});
