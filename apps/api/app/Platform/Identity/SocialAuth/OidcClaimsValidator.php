<?php

declare(strict_types=1);

namespace App\Platform\Identity\SocialAuth;

use App\Platform\Identity\SocialAuth\Data\SocialIdentity;
use App\Platform\Identity\SocialAuth\Exceptions\InvalidSocialClaimsException;

/**
 * Validates a DECODED OIDC id_token claim set and maps it to a {@see SocialIdentity}.
 *
 * This is the pure, network-free half of OIDC verification: signature/JWKS checking (which needs a
 * live provider and is exercised locally) happens in the concrete provider adapter, which then hands
 * the decoded claims here for the security checks that decide whether the token may be trusted —
 * issuer, audience (our client_id), nonce binding, and the temporal windows (exp/iat/nbf) with a
 * small clock-skew leeway. Every check fails closed with a specific reason.
 */
final class OidcClaimsValidator
{
    public function __construct(private readonly int $leeway = 60) {}

    /**
     * @param  array<string, mixed>  $claims
     *
     * @throws InvalidSocialClaimsException
     */
    public function validate(array $claims, string $expectedIssuer, string $expectedAudience, ?string $expectedNonce, ?int $now = null): void
    {
        $now ??= time();

        $iss = isset($claims['iss']) ? (string) $claims['iss'] : '';
        if ($iss === '' || ! hash_equals($expectedIssuer, $iss)) {
            throw new InvalidSocialClaimsException('iss');
        }

        // `aud` may be a single string or an array of audiences; ours must be present.
        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? array_map('strval', $aud) : [(string) $aud];
        if (! in_array($expectedAudience, $audiences, true)) {
            throw new InvalidSocialClaimsException('aud');
        }

        if ($expectedNonce !== null) {
            $nonce = isset($claims['nonce']) ? (string) $claims['nonce'] : '';
            if ($nonce === '' || ! hash_equals($expectedNonce, $nonce)) {
                throw new InvalidSocialClaimsException('nonce');
            }
        }

        $exp = isset($claims['exp']) ? (int) $claims['exp'] : null;
        if ($exp === null || $now > $exp + $this->leeway) {
            throw new InvalidSocialClaimsException('exp');
        }

        if (isset($claims['iat']) && (int) $claims['iat'] > $now + $this->leeway) {
            throw new InvalidSocialClaimsException('iat');
        }

        if (isset($claims['nbf']) && (int) $claims['nbf'] > $now + $this->leeway) {
            throw new InvalidSocialClaimsException('nbf');
        }

        if (! isset($claims['sub']) || (string) $claims['sub'] === '') {
            throw new InvalidSocialClaimsException('sub');
        }
    }

    /**
     * Map validated claims onto the normalised identity. Call ONLY after {@see validate()}.
     *
     * @param  array<string, mixed>  $claims
     */
    public function toIdentity(string $provider, array $claims): SocialIdentity
    {
        return new SocialIdentity(
            $provider,
            (string) $claims['sub'],
            isset($claims['email']) ? (string) $claims['email'] : null,
            (bool) ($claims['email_verified'] ?? false),
            isset($claims['name']) ? (string) $claims['name'] : null,
            $claims,
        );
    }
}
