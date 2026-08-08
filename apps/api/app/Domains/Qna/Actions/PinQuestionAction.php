<?php

declare(strict_types=1);

namespace App\Domains\Qna\Actions;

use App\Domains\Qna\Models\CourseQuestion;

/**
 * Pins or unpins a question so it floats to the top of the course listing. Authorization
 * (course instructor/super_admin) is enforced by CourseQuestionPolicy::pin before this runs.
 */
final class PinQuestionAction
{
    public function execute(CourseQuestion $question, bool $pinned): CourseQuestion
    {
        $question->pinned_at = $pinned ? now() : null;
        $question->save();

        return $question;
    }
}
