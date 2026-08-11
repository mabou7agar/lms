<?php

namespace App\Platform\Identity\Actions\Social;

use App\Platform\Identity\Exceptions\LastSignInMethodException;
use App\Platform\Identity\Models\SocialAccount;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Unlinks one linked social account, enforcing "never orphan an account": if removing this provider
 * would leave the user with no usable sign-in method — no other linked provider AND no self-chosen
 * password — the unlink is refused. Always leaves at least one usable credential.
 */
class UnlinkSocialAccountAction extends BaseAction
{
    public function execute(User $user, SocialAccount $account): void
    {
        $this->transaction(function () use ($user, $account): void {
            $otherProviders = $user->socialAccounts()
                ->whereKeyNot($account->getKey())
                ->count();

            // The last remaining sign-in method may never be removed. A user with a usable password
            // can always unlink every provider; a social-only user must keep at least one provider.
            if ($otherProviders === 0 && ! $user->hasUsablePassword()) {
                throw new LastSignInMethodException;
            }

            $account->delete();
        });
    }
}
