<?php

namespace App\Contexts\Learning\Support;

use App\Platform\Shared\Learning\Contracts\LessonAvailabilityPort;
use Carbon\CarbonInterface;

/**
 * Drip-inert default for {@see LessonAvailabilityPort}: every lesson is available immediately.
 * Returns an empty map (all ids treated as "no release constraint"), so drip has zero effect until
 * a real schedule provider is bound.
 */
final class NullLessonAvailabilityPort implements LessonAvailabilityPort
{
    /**
     * @param  list<int>  $lessonIds
     * @return array<int, ?CarbonInterface>
     */
    public function releaseAtForLessons(array $lessonIds, CarbonInterface $enrolledAt): array
    {
        return [];
    }
}
