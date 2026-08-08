<?php

namespace App\Domains\Reviews\Actions;

use App\Domains\Reviews\Models\CourseReview;
use App\Domains\Reviews\Models\CourseReviewVote;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Marks a review "helpful" for the calling learner. Idempotent: the unique (review_id, user_id)
 * index means a learner's vote exists at most once, and helpful_count is always RECOMPUTED from the
 * vote rows under a row lock — so repeated calls converge to the same count (never double-counts),
 * and concurrent double-taps cannot inflate it.
 *
 * Returns the resulting helpful_count.
 */
class ToggleHelpfulAction extends BaseAction
{
    public function execute(Actor $actor, CourseReview $review): int
    {
        $userId = $actor->actorId();

        return $this->transaction(function () use ($review, $userId): int {
            // Lock the vote rows for this review so two concurrent votes serialize.
            CourseReviewVote::query()
                ->where('review_id', $review->id)
                ->lockForUpdate()
                ->get();

            CourseReviewVote::query()->firstOrCreate([
                'review_id' => $review->id,
                'user_id' => $userId,
            ]);

            $count = CourseReviewVote::query()->where('review_id', $review->id)->count();

            $review->forceFill(['helpful_count' => $count])->save();

            return $count;
        });
    }
}
