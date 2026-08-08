<?php

declare(strict_types=1);

namespace App\Domains\Qna\Actions;

use App\Domains\Qna\Models\CourseQuestion;

/**
 * Soft-deletes a question. Ownership (author-only; super_admin bypasses via the policy) is enforced
 * by CourseQuestionPolicy::delete before this runs. Answers cascade at the database (FK cascade) only
 * on a hard delete; a soft-deleted question simply disappears from the tenant-scoped listing.
 */
final class DeleteQuestionAction
{
    public function execute(CourseQuestion $question): void
    {
        $question->delete();
    }
}
