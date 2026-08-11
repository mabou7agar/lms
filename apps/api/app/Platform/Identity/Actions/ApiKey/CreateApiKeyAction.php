<?php

namespace App\Platform\Identity\Actions\ApiKey;

use App\Platform\Identity\Enums\ApiScope;
use App\Platform\Identity\Models\User;
use DateTimeInterface;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issues a developer API key: a Sanctum personal access token whose abilities are a validated
 * subset of the {@see ApiScope} catalog, tagged with the creator's organization id so the key
 * stays tenant-scoped.
 *
 * The plaintext token lives only on the returned {@see NewAccessToken}; only its hash is stored.
 * Scope-catalog membership and the "≤ creator permissions" rule are enforced by the caller before
 * this action runs; the map-through {@see ApiScope::from()} here is a defensive final gate that
 * guarantees no non-catalog ability can ever reach the token row.
 */
final class CreateApiKeyAction
{
    /**
     * @param  list<string>  $scopes  catalog scope keys, already authorised for the creator
     */
    public function execute(User $creator, string $name, array $scopes, ?DateTimeInterface $expiresAt = null): NewAccessToken
    {
        $abilities = array_values(array_unique(array_map(
            static fn (string $scope): string => ApiScope::from($scope)->value,
            $scopes,
        )));

        $newToken = $creator->createToken($name, $abilities, $expiresAt);

        // Tenant-tag the freshly stored token row so listing/revocation are org-scoped.
        $newToken->accessToken->forceFill([
            'organization_id' => $creator->getAttribute('organization_id'),
        ])->save();

        return $newToken;
    }
}
