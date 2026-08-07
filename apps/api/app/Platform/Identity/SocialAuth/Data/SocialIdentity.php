<?php

declare(strict_types=1);

namespace App\Platform\Identity\SocialAuth\Data;

/**
 * The verified identity a social/SSO provider asserts about a user, normalised across every
 * provider (Google, Microsoft, Apple, generic OIDC, SAML) so the rest of Identity never sees a
 * provider's wire format.
 *
 * `subject` is the provider's STABLE, opaque user id (OIDC `sub`) — the only safe key to match a
 * returning user on, since emails can change or be reassigned. `emailVerified` reflects whether the
 * PROVIDER attests the email; account-linking treats an unverified email as untrusted so a social
 * login can never silently take over an existing local account.
 */
final class SocialIdentity
{
    /**
     * @param  array<string, mixed>  $raw  the provider's original claim set (never trusted for control flow)
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $subject,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name,
        public readonly array $raw = [],
    ) {}
}
