<?php

namespace App\Platform\Identity\Actions\Privacy;

use App\Platform\Identity\Models\DataSubjectRequest;
use App\Platform\Identity\Models\SocialAccount;
use App\Platform\Identity\Models\User;
use App\Platform\Identity\Models\UserDevice;
use App\Platform\Identity\Services\ConsentManager;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Assembles a portable copy of the data Identity holds about a user (PDPL/GDPR access & portability).
 *
 * This exports the Identity-owned records only. Other contexts (enrollments, orders, …) contribute
 * through their own read models in later work; the shape here is the stable envelope they extend, and
 * it deliberately never includes secrets (password hash, MFA secret, raw tokens).
 */
class ExportUserDataAction extends BaseAction
{
    public function __construct(private readonly ConsentManager $consents) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(User $user): array
    {
        return [
            'account' => [
                'id' => $user->public_id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'locale' => $user->locale,
                'timezone' => $user->getAttribute('timezone'),
                'email_verified' => $user->email_verified_at !== null,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'profile' => $user->profile()->first()?->toArray(),
            'consents' => $this->consents->all($user),
            'devices' => UserDevice::query()->where('user_id', $user->id)
                ->get(['name', 'ip', 'user_agent', 'last_used_at'])->toArray(),
            'social_accounts' => SocialAccount::query()->where('user_id', $user->id)
                ->get(['provider', 'email', 'created_at'])->toArray(),
            'data_requests' => DataSubjectRequest::query()->where('user_id', $user->id)
                ->get(['public_id', 'type', 'status', 'requested_at', 'completed_at'])->toArray(),
        ];
    }
}
