<?php

namespace App\Platform\Identity\Actions\Privacy;

use App\Platform\Identity\Enums\DataRequestStatus;
use App\Platform\Identity\Enums\DataRequestType;
use App\Platform\Identity\Models\DataSubjectRequest;
use App\Platform\Identity\Models\SocialAccount;
use App\Platform\Identity\Models\User;
use App\Platform\Identity\Models\UserConsent;
use App\Platform\Identity\Models\UserDevice;
use App\Platform\Shared\Actions\BaseAction;
use Illuminate\Support\Str;

/**
 * Fulfils an erasure request by PSEUDONYMISING the account rather than hard-deleting it.
 *
 * The account row is kept (soft-deleted) so that transactional records that legally must be retained
 * — orders, invoices/e-invoices, issued certificates — keep a valid foreign key, while every piece of
 * directly identifying data is scrubbed: name, email, phone, profile, linked social accounts, devices,
 * consent (with its stored IPs), and all access tokens are revoked. Any open erasure request the user
 * had is marked completed.
 */
class AnonymizeUserAction extends BaseAction
{
    public function execute(User $user): User
    {
        return $this->transaction(function () use ($user): User {
            // Revoke access.
            $user->tokens()->delete();
            UserDevice::query()->where('user_id', $user->id)->delete();

            // Remove directly identifying satellite records.
            SocialAccount::query()->where('user_id', $user->id)->delete();
            UserConsent::query()->where('user_id', $user->id)->delete();
            $user->profile()->delete();

            DataSubjectRequest::query()
                ->where('user_id', $user->id)
                ->where('type', DataRequestType::Erasure->value)
                ->whereIn('status', [DataRequestStatus::Pending->value, DataRequestStatus::InProgress->value])
                ->update(['status' => DataRequestStatus::Completed->value, 'completed_at' => now()]);

            // Pseudonymise the retained row.
            $user->forceFill([
                'name' => 'Deleted user',
                'email' => 'deleted-'.$user->id.'@deleted.invalid',
                'phone' => null,
                'password' => Str::password(40),
                'is_active' => false,
                'email_verified_at' => null,
                'phone_verified_at' => null,
                'mfa_enabled' => false,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            $user->delete(); // soft delete — the id survives for retained transactional records

            return $user;
        });
    }
}
