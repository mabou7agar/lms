<?php

namespace App\Platform\Media\Ports;

use App\Platform\Shared\Media\Contracts\MediaEnrollmentPort;

/**
 * P2/W04 - Safe default for MediaEnrollmentPort: the Media platform does not own enrollment or
 * publication state, so with no real implementation bound it denies course-media access to everyone
 * who is not the owner (owner access is decided in MediaAssetPolicy, not here). Learning rebinds a
 * real implementation (enrollment + preview grants + publication) when it loads.
 */
class NullMediaEnrollmentPort implements MediaEnrollmentPort
{
    public function canAccessCourseMedia(int $actorId, int $courseId): bool
    {
        return false;
    }
}
