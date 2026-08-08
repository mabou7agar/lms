<?php

declare(strict_types=1);

namespace App\Domains\Qna\Enums;

/**
 * Lifecycle of a course question. `Hidden` is a moderation outcome (removed from the learner-facing
 * list without hard-deleting the row), applied by the shared moderation queue.
 */
enum QuestionStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Hidden = 'hidden';

    /** Visible in the ordinary learner-facing listing (hidden questions are moderation-only). */
    public function isPubliclyVisible(): bool
    {
        return $this !== self::Hidden;
    }
}
