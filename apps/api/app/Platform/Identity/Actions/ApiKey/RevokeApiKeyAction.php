<?php

namespace App\Platform\Identity\Actions\ApiKey;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * Revokes (deletes) a developer API key, but ONLY within the caller's organization. The
 * organization filter is the tenant-isolation gate: an org-admin can never revoke another
 * organization's key even by guessing its id.
 */
final class RevokeApiKeyAction
{
    /** @return bool false when no matching key exists for this organization; true on deletion. */
    public function execute(int $organizationId, int $tokenId): bool
    {
        $token = PersonalAccessToken::query()
            ->where('organization_id', $organizationId)
            ->whereKey($tokenId)
            ->first();

        if ($token === null) {
            return false;
        }

        $token->delete();

        return true;
    }
}
