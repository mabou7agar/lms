<?php

namespace App\Domains\Catalog\Policies;

use App\Domains\Catalog\Enums\CatalogPermission;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Policies\BasePolicy;

/**
 * Course authorization. Reading published courses is public (no policy needed); mutations
 * require catalog management permissions. super_admin bypasses via before().
 *
 * These checks were doubly broken until now: the permission had no row (no seeder created
 * CatalogPermission) AND can() resolves a guard Sanctum does not match. Either alone was enough to
 * make every check fall through to the super_admin bypass, so an admin holding the capability
 * could not create, update or delete a course. Both halves are fixed — the slug is seeded by
 * CatalogSeeder, and hasPermission() pins the guard.
 */
class CoursePolicy extends BasePolicy
{
    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof Actor && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    public function create(Actor $user): bool
    {
        return $user->hasPermission(CatalogPermission::ManageCourses->value);
    }

    public function update(Actor $user, Course $course): bool
    {
        return $user->hasPermission(CatalogPermission::ManageCourses->value);
    }

    public function delete(Actor $user, Course $course): bool
    {
        return $user->hasPermission(CatalogPermission::ManageCourses->value);
    }
}
