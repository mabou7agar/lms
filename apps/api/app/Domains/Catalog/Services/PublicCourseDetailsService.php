<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Repositories\PublicCourseRepository;
use App\Platform\Shared\Commerce\Contracts\PurchaseSummaryPort;
use App\Platform\Shared\Services\BaseService;

/** Composes the public course aggregate with related courses and its commerce projection. */
final class PublicCourseDetailsService extends BaseService
{
    public function __construct(
        private readonly PublicCourseRepository $courses,
        private readonly RelatedCoursesService $related,
        private readonly PurchaseSummaryPort $purchases,
    ) {}

    public function find(string $identifier): ?Course
    {
        $course = $this->courses->findByIdentifier($identifier);

        if ($course === null) {
            return null;
        }

        $course->setRelation('related', $this->related->for($course));
        $course->setAttribute('purchase_summary', $this->purchases->forCourse((int) $course->id));

        return $course;
    }
}
