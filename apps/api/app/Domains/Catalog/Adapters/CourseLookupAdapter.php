<?php

namespace App\Domains\Catalog\Adapters;

use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Models\CourseTrainer;
use App\Platform\Shared\Catalog\Contracts\CourseLookupPort;

final class CourseLookupAdapter implements CourseLookupPort
{
    public function publishedCourseByPublicId(string $publicId): ?array
    {
        $course = Course::query()
            ->published()
            ->where('public_id', $publicId)
            ->first(['id', 'public_id', 'title']);

        if ($course === null) {
            return null;
        }

        return [
            'id' => (int) $course->getKey(),
            'public_id' => (string) $course->getAttribute('public_id'),
            'title' => (string) $course->getAttribute('title'),
        ];
    }

    /**
     * @return list<int>
     */
    public function trainerUserIds(int $courseId): array
    {
        return CourseTrainer::query()
            ->where('course_id', $courseId)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function courseTitle(int $courseId): ?string
    {
        $title = Course::query()->whereKey($courseId)->value('title');

        return $title === null ? null : (string) $title;
    }
}
