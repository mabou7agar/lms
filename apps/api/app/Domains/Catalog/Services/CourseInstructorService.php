<?php

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Enums\CatalogPermission;
use App\Domains\Catalog\Exceptions\InstructorAssignmentDeniedException;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Contracts\Actor;
use Illuminate\Support\Facades\DB;

/**
 * U4 - Authorized multi-instructor course assignment. Reuses the existing course_trainer pivot (its
 * composite (course_id, user_id) PK IS the unique (course_id, instructor_id) constraint) and writes
 * exclusively through raw DB statements — mirroring Course::syncTrainers — so the pivot's composite
 * key never trips Eloquent's single-key save path.
 *
 * AUTHORIZATION (permission-safe assignment): super_admin and holders of catalog.courses.manage may
 * manage any course. Everyone else may only manage instructors on a course they already train — so an
 * instructor can never attach themselves (or anyone) to a course they do not own/train.
 *
 * INTEGRITY: at most one primary instructor per course is enforced here (demote siblings in the same
 * transaction) AND, as defense in depth, by CourseTrainer's saved hook. Ordering is the `position`
 * column, 1-based and contiguous after reorder().
 *
 * TENANCY NOTE (T1, later phase): authorize() must additionally assert the instructor and the course
 * belong to the same organization once tenant scoping exists.
 */
class CourseInstructorService
{
    /**
     * Attach (or update) an instructor on a course. Idempotent on (course_id, instructor_id). When
     * $position is null the instructor is appended to the end of the current order.
     */
    public function assign(
        Course $course,
        int $instructorId,
        Actor $actor,
        ?string $role = null,
        bool $isPrimary = false,
        ?int $position = null,
    ): void {
        $this->authorize($actor, $course);

        DB::transaction(function () use ($course, $instructorId, $role, $isPrimary, $position): void {
            $existingPosition = DB::table('course_trainer')
                ->where('course_id', $course->id)
                ->where('user_id', $instructorId)
                ->value('position');

            // Explicit position wins; otherwise keep an existing row's order, or append to the end.
            $resolvedPosition = match (true) {
                $position !== null => $position,
                $existingPosition !== null => (int) $existingPosition,
                default => (int) DB::table('course_trainer')
                    ->where('course_id', $course->id)
                    ->max('position') + 1,
            };

            DB::table('course_trainer')->updateOrInsert(
                ['course_id' => $course->id, 'user_id' => $instructorId],
                ['role' => $role, 'position' => $resolvedPosition, 'is_primary' => $isPrimary],
            );

            if ($isPrimary) {
                $this->demoteOtherPrimaries($course->id, $instructorId);
            }
        });
    }

    /** Detach an instructor from a course (no-op if not attached). */
    public function unassign(Course $course, int $instructorId, Actor $actor): void
    {
        $this->authorize($actor, $course);

        DB::table('course_trainer')
            ->where('course_id', $course->id)
            ->where('user_id', $instructorId)
            ->delete();
    }

    /** Promote an already-attached instructor to the course's single primary. */
    public function setPrimary(Course $course, int $instructorId, Actor $actor): void
    {
        $this->authorize($actor, $course);

        DB::transaction(function () use ($course, $instructorId): void {
            $updated = DB::table('course_trainer')
                ->where('course_id', $course->id)
                ->where('user_id', $instructorId)
                ->update(['is_primary' => true]);

            if ($updated === 0) {
                throw new InstructorAssignmentDeniedException('That instructor is not assigned to this course.');
            }

            $this->demoteOtherPrimaries($course->id, $instructorId);
        });
    }

    /**
     * Reorder the course's instructors to match the given instructor-id order. Ids not currently
     * assigned are ignored; positions are rewritten 1-based in the supplied order.
     *
     * @param  array<int, int|string>  $orderedInstructorIds
     */
    public function reorder(Course $course, array $orderedInstructorIds, Actor $actor): void
    {
        $this->authorize($actor, $course);

        DB::transaction(function () use ($course, $orderedInstructorIds): void {
            $position = 1;
            foreach (array_values($orderedInstructorIds) as $instructorId) {
                DB::table('course_trainer')
                    ->where('course_id', $course->id)
                    ->where('user_id', (int) $instructorId)
                    ->update(['position' => $position++]);
            }
        });
    }

    private function demoteOtherPrimaries(int $courseId, int $keepInstructorId): void
    {
        DB::table('course_trainer')
            ->where('course_id', $courseId)
            ->where('user_id', '!=', $keepInstructorId)
            ->update(['is_primary' => false]);
    }

    private function authorize(Actor $actor, Course $course): void
    {
        if ($actor->hasRole('super_admin')) {
            return;
        }

        if ($actor->hasPermission(CatalogPermission::ManageCourses->value)) {
            return;
        }

        // A non-admin may only manage instructors on a course they already train — never self-attach
        // to a course they do not own/train.
        if ($course->isTrainedBy($actor->actorId())) {
            return;
        }

        throw new InstructorAssignmentDeniedException('You are not allowed to manage instructors for this course.');
    }
}
