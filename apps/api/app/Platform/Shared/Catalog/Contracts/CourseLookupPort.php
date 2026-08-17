<?php

namespace App\Platform\Shared\Catalog\Contracts;

interface CourseLookupPort
{
    /**
     * Resolve a published course by public id without exposing Catalog's Eloquent model.
     *
     * @return array{id:int, public_id:string, title:string}|null
     */
    public function publishedCourseByPublicId(string $publicId): ?array;

    /**
     * The platform user ids of the people who teach this course.
     *
     * Needed by any context that has to reach a course TEAM without knowing what a course is — the
     * Q&A overdue sweep being the first: a question nobody has answered has to be escalated to
     * somebody, and "somebody" is defined by Catalog, not by Q&A.
     *
     * Empty for an unknown course, which is the same as a course with nobody to tell.
     *
     * @return list<int>
     */
    public function trainerUserIds(int $courseId): array;

    /**
     * A course's display title by internal id, or null when there is no such course. A notification
     * that says which course it is about needs this and nothing else.
     */
    public function courseTitle(int $courseId): ?string;
}
