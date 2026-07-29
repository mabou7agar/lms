<?php

namespace App\Platform\Shared\Curriculum\Contracts;

use App\Platform\Shared\Curriculum\Data\CourseRef;
use App\Platform\Shared\Curriculum\Data\LessonRef;
use App\Platform\Shared\Curriculum\Data\SectionRef;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only projection of curriculum data for the Learning context. Phase 1 scope: the
 * enrollability check and the model→DTO mappers required for resource rendering. Implementations
 * map ALREADY-LOADED models to DTOs (no extra queries) so callers stop reading foreign Eloquent
 * models directly. Parameters are typed as the framework base Model to keep this Shared contract
 * free of any Authoring/Catalog class reference.
 */
interface CurriculumReadPort
{
    /** True when the course may be enrolled into (published). Replaces the CourseStatus check. */
    public function isCourseEnrollable(int $courseId): bool;

    public function courseRef(Model $course): CourseRef;

    public function sectionRef(Model $section): SectionRef;

    public function lessonRef(Model $lesson): LessonRef;

    /**
     * Published lesson ids for a course (percentage math). Order matches the prior
     * Section->Lesson published pluck.
     *
     * @return list<int>
     */
    public function publishedLessonIdsForCourse(int $courseId): array;

    /**
     * Published lesson ids for a single section.
     *
     * @return list<int>
     */
    public function publishedLessonIdsForSection(int $sectionId): array;

    /** The course id a lesson belongs to (via its section), or null if unresolved. */
    public function courseIdForLesson(int $lessonId): ?int;

    /**
     * Prerequisite lesson ids for many lessons in ONE query, keyed by lesson id. Every supplied id
     * appears in the result (an empty list when a lesson has no prerequisites), so a caller batching
     * an access check over a whole curriculum never issues a query per lesson.
     *
     * @param  list<int>  $lessonIds
     * @return array<int, list<int>>
     */
    public function prerequisitesForLessonIds(array $lessonIds): array;

    // --- Phase 3A id/ref read methods (expand). Consumed by later phases; additive. ---

    /** Resolve a course by its public id, or null. */
    public function findCourseByPublicId(string $publicId): ?CourseRef;

    /** Resolve a course by its internal id, or null. */
    public function courseRefById(int $courseId): ?CourseRef;

    /** Resolve a fully-populated lesson ref (incl. sectionId, courseId, position, prerequisites) by id, or null. */
    public function lessonRefById(int $lessonId): ?LessonRef;

    /** Resolve a fully-populated lesson ref by its public id, or null (route resolution). */
    public function findLessonByPublicId(string $publicId): ?LessonRef;

    /**
     * The ordered curriculum tree for a course: the course ref plus its sections (each with its
     * ordered lessons). `publishedOnly` filters to published sections/lessons.
     *
     * @return array{course: ?CourseRef, sections: list<array{section: SectionRef, lessons: list<LessonRef>}>}
     */
    public function curriculumTree(int $courseId, bool $publishedOnly): array;

    /**
     * Published lessons for a course in curriculum order (section position, then lesson position).
     *
     * @return list<LessonRef>
     */
    public function orderedPublishedLessonRefs(int $courseId): array;
}
