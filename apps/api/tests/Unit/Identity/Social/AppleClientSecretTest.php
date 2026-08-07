<?php

use App\Platform\Identity\SocialAuth\Apple\AppleClientSecret;
use App\Platform\Identity\SocialAuth\Jwt\Der;

it('generates a verifiable ES256 client secret with the right claims', function () {
    [$jwk, , $resource] = ssoEcKey();
    if ($jwk === null) {
        $this->markTestSkipped('EC key generation unavailable in this environment.');
    }

    $pem = '';
    openssl_pkey_export($resource, $pem);

    $config = [
        'team_id' => 'TEAMID', 'key_id' => 'KEYID', 'client_id' => 'com.app.service',
        'private_key' => base64_encode($pem),
    ];

    $jwt = (new AppleClientSecret($config))->generate();

    [$head, $body, $sig] = explode('.', $jwt);
    $header = json_decode(ssoB64uDecode($head), true);
    $payload = json_decode(ssoB64uDecode($body), true);

    expect($header['alg'])->toBe('ES256')
        ->and($header['kid'])->toBe('KEYID')
        ->and($payload['iss'])->toBe('TEAMID')
        ->and($payload['aud'])->toBe('https://appleid.apple.com')
        ->and($payload['sub'])->toBe('com.app.service')
        ->and($payload['exp'])->toBeGreaterThan($payload['iat']);

    // The signature verifies against the matching public key.
    $publicPem = openssl_pkey_get_details($resource)['key'];
    $verified = openssl_verify("{$head}.{$body}", Der::ecSignatureToDer(ssoB64uDecode($sig)), $publicPem, OPENSSL_ALGO_SHA256);

    expect($verified)->toBe(1);
});
