<?php

namespace App\Domains\Reviews\Actions;

use App\Domains\Reviews\Models\CourseReview;
use App\Domains\Reviews\Services\ReviewAggregateService;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Html\HtmlSanitizer;

/**
 * Updates an existing review's rating and/or body. Ownership is authorized by CourseReviewPolicy at
 * the controller (own review only); this action just applies the change, sanitizing the body, and
 * recomputes the aggregate because the rating may have moved.
 */
class UpdateReviewAction extends BaseAction
{
    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly ReviewAggregateService $aggregates,
    ) {}

    /**
     * @param  array{rating?: int, body?: string|null}  $data  Only supplied keys are applied.
     */
    public function execute(CourseReview $review, array $data): CourseReview
    {
        return $this->transaction(function () use ($review, $data): CourseReview {
            if (array_key_exists('rating', $data)) {
                $review->rating = (int) $data['rating'];
            }

            if (array_key_exists('body', $data)) {
                $review->body = $data['body'] !== null ? $this->sanitizer->sanitize((string) $data['body']) : null;
            }

            $review->save();

            $this->aggregates->recompute((int) $review->course_id);

            return $review;
        });
    }
}
