<?php

namespace App\Platform\Identity\Contracts;

use App\Platform\Identity\Contracts\Data\InstructorProfileRef;
use App\Platform\Identity\Contracts\Data\UserRef;

/**
 * Resolve or list users by identifier, returning boundary-safe UserRef(s) / scalars — never
 * Eloquent models. Lets other contexts render an owner or find a user without importing
 * App\Platform\Identity\Models\User. Implemented inside the Identity context (later phase).
 *
 * NOTE: lookup by "username" is intentionally omitted — there is no `username` column in the
 * current schema (email is the credential, public_id the external key). See the Phase 1 report.
 * Organization-membership lookup is also omitted here; that surface is deferred with
 * UserOrganizationPort until Identity-vs-CRM ownership of membership is resolved.
 */
interface UserLookupPort
{
    /** Resolve a user by internal id, or null. */
    public function refById(int $id): ?UserRef;

    /** Resolve a user by external public id, or null. */
    public function refByPublicId(string $publicId): ?UserRef;

    /** Resolve just the internal id for an email (e.g. CRM invite matching), or null. */
    public function idByEmail(string $email): ?int;

    /**
     * Active users holding the instructor role, ordered for display (trainer listing).
     * The is_active / role filtering is an Identity implementation detail, not part of the contract.
     *
     * @return list<UserRef>
     */
    public function instructors(): array;

    /**
     * Public instructor directory (U4): active users holding the instructor role whose profile is
     * marked public, ordered by profile display_order then name. Each ref carries the public profile
     * plus its media as REFERENCES (never resolved URLs). The is_active / role / is_public filtering
     * is an Identity implementation detail, not part of the contract.
     *
     * @return list<InstructorProfileRef>
     */
    public function instructorProfiles(): array;

    /** Total number of user accounts (reproduces User::query()->count()). */
    public function totalCount(): int;

    /**
     * Resolve many users to boundary-safe UserRefs, keyed by user id, preserving the input order
     * and silently skipping ids with no matching user (never exposes forbidden Identity fields).
     *
     * @param  array<int, int>  $userIds
     * @return array<int, UserRef>
     */
    public function refsByIds(array $userIds): array;
}
