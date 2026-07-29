<?php

namespace App\Domains\Authoring\Services;

use App\Domains\Catalog\Contracts\CoursePublishGuard;
use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Publishing\Data\CourseReadinessInput;
use App\Platform\Shared\Publishing\Data\ReadinessReport;

/**
 * Authoring's implementation of Catalog's CoursePublishGuard. Bound in AuthoringServiceProvider
 * so publishing a course is blocked unless its curriculum is valid. This inverts the
 * dependency (Authoring depends on Catalog, never the reverse).
 *
 * The verdict is derived from the readiness report, not computed alongside it — see the interface
 * docblock. There is one rule set, in CourseReadinessService.
 *
 * `report()` takes the flattened input rather than a Course so that this file keeps exactly one
 * reference to Catalog's model. Authoring→Catalog is not an allowed dependency; the single
 * reference here is grandfathered, and widening it would mean growing the Deptrac baseline.
 */
class CurriculumPublishGuard implements CoursePublishGuard
{
    private ?string $reason = null;

    public function __construct(private readonly CourseReadinessService $readiness) {}

    public function canPublish(Course $course): bool
    {
        $report = $this->report(new CourseReadinessInput(
            courseId: (int) $course->getKey(),
            coursePublicId: (string) $course->getAttribute('public_id'),
            description: $course->getAttribute('description'),
            thumbnailPath: $course->getAttribute('thumbnail_path'),
            hasInstructor: $course->trainerLinks()->exists(),
            visibility: $course->getAttribute('visibility')?->value,
        ));

        $this->reason = $report->firstBlockerReason();

        return $report->isPublishable();
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function report(CourseReadinessInput $course): ReadinessReport
    {
        return $this->readiness->evaluate($course);
    }
}
