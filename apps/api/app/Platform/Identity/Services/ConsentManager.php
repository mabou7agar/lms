<?php

namespace App\Platform\Identity\Services;

use App\Platform\Identity\Enums\ConsentPurpose;
use App\Platform\Identity\Models\User;
use App\Platform\Identity\Models\UserConsent;
use App\Platform\Shared\Services\BaseService;
use Illuminate\Support\Collection;

/**
 * Records and reads per-purpose consent. Each grant/withdrawal upserts the user's current decision
 * for that purpose and stamps when it happened (and the policy version + source IP), so the record
 * is auditable and re-consent can be detected when a policy version changes.
 */
class ConsentManager extends BaseService
{
    public function record(User $user, ConsentPurpose $purpose, bool $granted, ?string $version = null, ?string $ip = null): UserConsent
    {
        /** @var UserConsent $consent */
        $consent = UserConsent::query()->updateOrCreate(
            ['user_id' => $user->id, 'purpose' => $purpose->value],
            [
                'granted' => $granted,
                'version' => $version,
                'source_ip' => $ip,
                'granted_at' => $granted ? now() : null,
                'revoked_at' => $granted ? null : now(),
            ],
        );

        return $consent;
    }

    public function has(User $user, ConsentPurpose $purpose): bool
    {
        return UserConsent::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose->value)
            ->where('granted', true)
            ->exists();
    }

    /**
     * Current decision per purpose (defaults to false for purposes never decided).
     *
     * @return array<string, bool>
     */
    public function all(User $user): array
    {
        $granted = UserConsent::query()
            ->where('user_id', $user->id)
            ->pluck('granted', 'purpose');

        return Collection::make(ConsentPurpose::cases())
            ->mapWithKeys(fn (ConsentPurpose $p): array => [$p->value => (bool) ($granted[$p->value] ?? false)])
            ->all();
    }
}
