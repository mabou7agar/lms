<?php

namespace App\Contexts\Learning\Adapters;

use App\Contexts\Learning\Models\Enrollment;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Media\Contracts\MediaEnrollmentPort;

/**
 * Learning's real implementation of MediaEnrollmentPort (rebinds the Media platform's deny-by-default
 * NullMediaEnrollmentPort). Media owns asset metadata but not enrollment/publication state, so it
 * delegates "may this actor play this course's media?" here.
 *
 * Access is granted when the actor has an ACTIVE enrollment in the course, or the course is
 * published (enrollable) — a published course exposes its preview media to browsing learners. Only
 * scalars cross the boundary; enrollment is read from Learning's own Enrollment model and
 * publication through CurriculumReadPort, so no Catalog/Authoring model is imported.
 */
class MediaEnrollmentAdapter implements MediaEnrollmentPort
{
    public function __construct(private readonly CurriculumReadPort $curriculum) {}

    public function canAccessCourseMedia(int $actorId, int $courseId): bool
    {
        $enrolled = Enrollment::query()
            ->where('course_id', $courseId)
            ->where('user_id', $actorId)
            ->active()
            ->exists();

        if ($enrolled) {
            return true;
        }

        return $this->curriculum->isCourseEnrollable($courseId);
    }
}
