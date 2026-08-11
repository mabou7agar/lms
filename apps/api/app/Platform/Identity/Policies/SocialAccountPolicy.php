<?php

namespace App\Platform\Identity\Policies;

use App\Platform\Identity\Models\SocialAccount;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Policies\BasePolicy;

/**
 * A user may only view/unlink their OWN linked social accounts.
 */
class SocialAccountPolicy extends BasePolicy
{
    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof User && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    public function delete(User $user, SocialAccount $account): bool
    {
        return $account->user_id === $user->id;
    }
}
