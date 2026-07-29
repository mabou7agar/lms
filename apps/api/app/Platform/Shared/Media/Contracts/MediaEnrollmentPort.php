<?php

namespace App\Platform\Shared\Media\Contracts;

/**
 * P2/W04 - Answers "may this actor play media that belongs to this course?" without the Media
 * platform importing Learning/Catalog. Media owns asset metadata but NOT enrollment/publication
 * state, so playback authorization for a non-owner is delegated here. Passes only scalars, so it
 * stays in Shared (the graph leaf) with no Media dependency.
 *
 * The Media platform binds a deny-by-default null implementation; Learning (which owns enrollment,
 * preview grants and publication) rebinds a real implementation when it loads.
 */
interface MediaEnrollmentPort
{
    /**
     * True when the actor may access this course's media through enrollment, an active preview
     * grant, publication, or an explicit permission. Never leaks why access was granted/denied.
     */
    public function canAccessCourseMedia(int $actorId, int $courseId): bool;
}
