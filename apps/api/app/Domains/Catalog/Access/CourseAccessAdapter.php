<?php

namespace App\Domains\Catalog\Access;

use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Models\CourseTrainer;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use Illuminate\Support\Facades\Gate;

/**
 * Catalog-side implementation of CourseAccessPort. Lives here because Catalog owns the Course
 * model — importing it anywhere else would be a layer violation.
 *
 * It deliberately does NOT restate the ownership rule (super_admin/admin bypass, assigned trainer,
 * course not archived). It delegates to the `authoring.manage-curriculum` gate, which is the single
 * definition of that rule in the codebase. The reference is a gate NAME, not a class, so no
 * compile-time dependency on Authoring is created — and if that gate's definition changes, every
 * consumer of this port changes with it automatically.
 *
 * An undefined gate denies, so this is fail-closed if Authoring's provider is ever unloaded.
 */
class CourseAccessAdapter implements CourseAccessPort
{
    /** Owned and defined by App\Domains\Authoring\Providers\AuthoringServiceProvider. */
    private const MANAGE_CURRICULUM = 'authoring.manage-curriculum';

    /**
     * The permission whose holders the curriculum gate lets through unconditionally. Named as a
     * string for the same reason the gate above is: Catalog may not import an Authoring enum, and
     * the gate remains the single decider — this only recognises the case where asking it per course
     * would return true for every course in the catalogue.
     */
    private const MANAGE_CURRICULUM_PERMISSION = 'authoring.curriculum.manage';

    public function canManageContent(Actor $actor, int $courseId): bool
    {
        $course = Course::query()->find($courseId);

        if ($course === null) {
            return false;
        }

        return Gate::forUser($actor)->allows(self::MANAGE_CURRICULUM, $course);
    }

    public function manageableCourseId(Actor $actor, string $coursePublicId): ?int
    {
        $course = Course::query()->where('public_id', $coursePublicId)->first();

        if ($course === null || ! Gate::forUser($actor)->allows(self::MANAGE_CURRICULUM, $course)) {
            return null;
        }

        return (int) $course->id;
    }

    /**
     * @return list<int>
     */
    public function manageableCourseIds(Actor $actor): array
    {
        // The gate is the single definition of ownership and stays the decider — but it asks
        // isTrainedBy(), which is a query per course, so handing it the whole catalogue would be one
        // query per course in the system. The candidate set is narrowed first by the only route a
        // scoped instructor can own a course (their trainer links), and the gate then confirms each
        // one, which is where archived-course and tenant-visibility rules still get applied.
        //
        // A bypass holder (super_admin, or anyone granted the manage-curriculum permission) manages
        // everything by definition, so the gate would answer true for every row; their set is read
        // as plain ids instead of being re-derived one gate call at a time.
        if ($actor->hasRole('super_admin') || $actor->hasPermission(self::MANAGE_CURRICULUM_PERMISSION)) {
            return Course::query()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        }

        $candidateIds = CourseTrainer::query()
            ->where('user_id', $actor->actorId())
            ->pluck('course_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique();

        if ($candidateIds->isEmpty()) {
            return [];
        }

        return Course::query()
            ->whereKey($candidateIds)
            ->get()
            ->filter(fn (Course $course): bool => Gate::forUser($actor)->allows(self::MANAGE_CURRICULUM, $course))
            ->map(static fn (Course $course): int => (int) $course->id)
            ->values()
            ->all();
    }
}
