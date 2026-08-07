<?php

declare(strict_types=1);

namespace App\Platform\Identity\SocialAuth\Providers;

use App\Platform\Identity\SocialAuth\Contracts\SocialIdentityProvider;
use App\Platform\Identity\SocialAuth\Data\SocialIdentity;

/**
 * Deterministic, network-free social provider — the testing/local seam, exactly analogous to the
 * Commerce FakeGateway. It lets the entire social-login pipeline (routes → state → linking → token
 * issuance) run end-to-end without any external IdP, and is refused in production by the
 * SocialAuthManager unless SSO_ALLOW_FAKE_PROVIDER is set for a deliberate non-auth environment.
 *
 * The authorization "code" doubles as the test's control surface: a pipe-delimited
 * `subject|email|emailVerified|name` string (only `subject` is required) maps straight onto the
 * returned identity, so a caller can drive any linking scenario deterministically.
 */
final class FakeSocialProvider implements SocialIdentityProvider
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config = []) {}

    public function key(): string
    {
        return 'fake';
    }

    public function authorizationUrl(string $state, string $nonce, string $redirectUri): string
    {
        return 'https://fake-sso.test/authorize?'.http_build_query([
            'state' => $state,
            'nonce' => $nonce,
            'redirect_uri' => $redirectUri,
        ]);
    }

    public function exchange(string $code, string $nonce, string $redirectUri): SocialIdentity
    {
        $parts = explode('|', $code);

        $subject = trim($parts[0]) !== '' ? trim($parts[0]) : 'fake-subject';
        $email = isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : $subject.'@fake-sso.test';
        $emailVerified = isset($parts[2]) ? trim($parts[2]) === '1' : true;
        $name = isset($parts[3]) && trim($parts[3]) !== '' ? trim($parts[3]) : null;

        return new SocialIdentity('fake', $subject, $email, $emailVerified, $name, ['code' => $code]);
    }
}
