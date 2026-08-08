<?php

namespace App\Domains\Reviews\Actions;

use App\Domains\Reviews\Models\CourseReview;
use App\Domains\Reviews\Services\ReviewAggregateService;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Soft-deletes a review (own review, or a moderator per CourseReviewPolicy — authorized at the
 * controller) and recomputes the aggregate so the removed rating stops counting.
 */
class DeleteReviewAction extends BaseAction
{
    public function __construct(private readonly ReviewAggregateService $aggregates) {}

    public function execute(CourseReview $review): void
    {
        $courseId = (int) $review->course_id;

        $this->transaction(function () use ($review, $courseId): void {
            $review->delete();

            $this->aggregates->recompute($courseId);
        });
    }
}
