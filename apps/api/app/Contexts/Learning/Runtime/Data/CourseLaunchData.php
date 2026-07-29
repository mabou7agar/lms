<?php

namespace App\Contexts\Learning\Runtime\Data;

/**
 * The authorized learner "course shell" returned by a launch: enough to open the player without
 * yet loading every lesson body. Only published, currently-available content is summarized here.
 */
final readonly class CourseLaunchData
{
    public function __construct(
        public string $coursePublicId,
        public string $title,
        public string $slug,
        public string $enrollmentPublicId,
        public string $enrollmentStatus,
        public int $progressPercentage,
        public int $totalLessons,
        public int $completedLessons,
        public ?string $resumeLessonPublicId,
        public ?string $resumeLessonTitle,
    ) {}
}
