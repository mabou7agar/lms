<?php

namespace App\Domains\Reviews\Actions;

use App\Domains\Reviews\Enums\ReviewStatus;
use App\Domains\Reviews\Exceptions\DuplicateReviewException;
use App\Domains\Reviews\Exceptions\ReviewNotAllowedException;
use App\Domains\Reviews\Models\CourseReview;
use App\Domains\Reviews\Services\ReviewAggregateService;
use App\Domains\Reviews\Support\CourseLookup;
use App\Domains\Reviews\Support\CourseTenantVisibility;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Html\HtmlSanitizer;
use App\Platform\Shared\Learning\Contracts\CourseEnrollmentPort;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Creates a learner's review of a course, enforcing every business rule server-side:
 *   - the course must exist AND be visible to the active tenant (else 404, indistinguishable);
 *   - the caller must NOT be the course's own instructor (isTrainedBy / manages-content = false);
 *   - the caller must be enrolled in / entitled to the course (course access);
 *   - at most one active review per (course, user) — a second attempt is a 409;
 *   - the body is sanitized (the single sanitization point) before persistence;
 *   - organization_id is stamped from the COURSE server-side (never from client input);
 *   - verified reflects genuine enrollment.
 * The rating aggregate is recomputed in the same logical operation.
 */
class CreateReviewAction extends BaseAction
{
    public function __construct(
        private readonly CourseLookup $courses,
        private readonly CourseAccessPort $access,
        private readonly CourseEnrollmentPort $enrollment,
        private readonly HtmlSanitizer $sanitizer,
        private readonly ReviewAggregateService $aggregates,
    ) {}

    public function execute(Actor $actor, string $coursePublicId, int $rating, ?string $body = null): CourseReview
    {
        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages(['rating' => 'Rating must be between 1 and 5.']);
        }

        $course = $this->courses->byPublicId($coursePublicId);

        // A missing course and another tenant's private course are deliberately indistinguishable.
        if ($course === null || ! CourseTenantVisibility::visible($course->organization_id)) {
            throw new NotFoundHttpException('Course not found.');
        }

        $courseId = (int) $course->id;
        $userId = $actor->actorId();

        // The course's own instructor (or a content manager/admin) cannot review it.
        if ($this->access->canManageContent($actor, $courseId)) {
            throw new ReviewNotAllowedException('The course instructor cannot review their own course.');
        }

        // Only an enrolled / entitled learner may review (active OR completed course access).
        if (! $this->enrollment->hasCourseAccess($courseId, $userId)) {
            throw new ReviewNotAllowedException('You must be enrolled in this course to review it.');
        }

        return $this->transaction(function () use ($course, $courseId, $userId, $rating, $body): CourseReview {
            $alreadyReviewed = CourseReview::query()
                ->where('course_id', $courseId)
                ->where('user_id', $userId)
                ->exists();

            if ($alreadyReviewed) {
                throw new DuplicateReviewException;
            }

            $review = new CourseReview;
            $review->fill([
                'course_id' => $courseId,
                'user_id' => $userId,
                'rating' => $rating,
                'body' => $body !== null ? $this->sanitizer->sanitize($body) : null,
                'status' => ReviewStatus::Published->value,
                'helpful_count' => 0,
            ]);

            // Never mass-assignable: organization_id is stamped from the course; verified from access.
            $review->forceFill([
                'organization_id' => $course->organization_id,
                'verified' => true,
            ])->save();

            $this->aggregates->recompute($courseId);

            return $review;
        });
    }
}
