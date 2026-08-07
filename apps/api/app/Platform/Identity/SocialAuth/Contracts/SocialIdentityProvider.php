<?php

declare(strict_types=1);

namespace App\Platform\Identity\SocialAuth\Contracts;

use App\Platform\Identity\SocialAuth\Data\SocialIdentity;

/**
 * A social/SSO provider adapter. Concrete adapters (fake, Google/Microsoft/generic OIDC, Apple,
 * SAML) implement this; the SocialAuthManager resolves the configured one and the rest of Identity
 * depends only on this contract — never on a vendor's wire format.
 *
 * `authorizationUrl()` builds the provider's consent URL (carrying our CSRF `state` and OIDC
 * `nonce`). `exchange()` completes the flow: it swaps the returned authorization `code` for tokens,
 * verifies them, and returns the normalised {@see SocialIdentity}. For real providers the network
 * round-trip and signature/JWKS verification inside `exchange()` require live credentials and are
 * exercised locally (LOCAL REQUIRED); the fake adapter implements the same contract deterministically
 * with no network so the whole pipeline is testable.
 */
interface SocialIdentityProvider
{
    /** The provider key (e.g. 'google', 'fake') this adapter serves. */
    public function key(): string;

    /** Build the provider consent URL carrying our signed CSRF state and OIDC nonce. */
    public function authorizationUrl(string $state, string $nonce, string $redirectUri): string;

    /** Exchange the authorization code for a verified, normalised identity. */
    public function exchange(string $code, string $nonce, string $redirectUri): SocialIdentity;
}
