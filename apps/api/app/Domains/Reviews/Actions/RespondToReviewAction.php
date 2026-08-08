<?php

namespace App\Domains\Reviews\Actions;

use App\Domains\Reviews\Models\CourseReview;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Html\HtmlSanitizer;

/**
 * Records the course instructor's public response to a review. Authorization (course instructor or
 * super_admin) is enforced by CourseReviewPolicy::respond at the controller; this action sanitizes
 * the response and stamps who responded and when. Does not affect the rating aggregate.
 */
class RespondToReviewAction extends BaseAction
{
    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    public function execute(Actor $actor, CourseReview $review, string $response): CourseReview
    {
        $review->forceFill([
            'instructor_response' => $this->sanitizer->sanitize($response),
            'responded_by' => $actor->actorId(),
            'responded_at' => now(),
        ])->save();

        return $review;
    }
}
