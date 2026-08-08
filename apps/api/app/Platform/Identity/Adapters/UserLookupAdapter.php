<?php

namespace App\Platform\Identity\Adapters;

use App\Platform\Identity\Contracts\Data\InstructorProfileRef;
use App\Platform\Identity\Contracts\Data\UserRef;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Identity\Models\User;
use App\Platform\Identity\Models\UserProfile;

/**
 * Resolves/lists users as boundary-safe UserRef(s) / scalars. The profile relation is eager-loaded
 * so UserRef can carry avatar/headline without an N+1. Lives inside Identity.
 */
final class UserLookupAdapter implements UserLookupPort
{
    public function refById(int $id): ?UserRef
    {
        return User::query()->with('profile')->find($id)?->toUserRef();
    }

    public function refByPublicId(string $publicId): ?UserRef
    {
        return User::query()->with('profile')->where('public_id', $publicId)->first()?->toUserRef();
    }

    public function idByEmail(string $email): ?int
    {
        $id = User::query()->where('email', $email)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Active users holding the 'instructor' role, ordered by name. Mirrors the existing
     * TrainerController query exactly (is_active + roles.name = 'instructor' + eager profile).
     *
     * @return list<UserRef>
     */
    public function instructors(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'instructor'))
            ->with('profile')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): UserRef => $user->toUserRef())
            ->values()
            ->all();
    }

    /**
     * Active instructors with a public profile, ordered by display_order then name. Media fields are
     * emitted as stored references (a MediaAsset public_id or legacy path) — resolving to a URL is the
     * Media platform's job. i18n headline/bio are emitted as both their locale maps and a resolved
     * scalar for the active locale (via the model's HasTranslations::localized()).
     *
     * @return list<InstructorProfileRef>
     */
    public function instructorProfiles(): array
    {
        $refs = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'instructor'))
            ->whereHas('profile', fn ($q) => $q->where('is_public', true))
            ->with('profile')
            ->get()
            ->map(fn (User $user): InstructorProfileRef => $this->toInstructorProfileRef($user))
            ->all();

        usort(
            $refs,
            static fn (InstructorProfileRef $a, InstructorProfileRef $b): int
                => [$a->displayOrder, $a->name] <=> [$b->displayOrder, $b->name],
        );

        return array_values($refs);
    }

    private function toInstructorProfileRef(User $user): InstructorProfileRef
    {
        /** @var UserProfile|null $profile */
        $profile = $user->profile;

        $headline = $profile?->localized('headline');
        $bio = $profile?->localized('bio');

        return new InstructorProfileRef(
            publicId: (string) $user->public_id,
            name: (string) $user->name,
            headline: is_string($headline) && $headline !== '' ? $headline : null,
            bio: is_string($bio) && $bio !== '' ? $bio : null,
            headlineI18n: is_array($profile?->headline_i18n) ? $profile->headline_i18n : [],
            bioI18n: is_array($profile?->bio_i18n) ? $profile->bio_i18n : [],
            specialties: is_array($profile?->specialties) ? $profile->specialties : [],
            socialLinks: is_array($profile?->social_links) ? $profile->social_links : [],
            website: $profile?->website,
            profilePhoto: $profile?->profile_photo,
            coverPhoto: $profile?->cover_photo,
            avatarPath: $profile?->avatar_path,
            displayOrder: (int) ($profile?->display_order ?? 0),
        );
    }

    public function totalCount(): int
    {
        return User::query()->count();
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, UserRef>
     */
    public function refsByIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $byId = User::query()->with('profile')->whereIn('id', $userIds)->get()->keyBy('id');

        $refs = [];
        foreach ($userIds as $id) {
            $user = $byId->get((int) $id);
            if ($user !== null) {
                $refs[(int) $id] = $user->toUserRef();
            }
        }

        return $refs;
    }
}
