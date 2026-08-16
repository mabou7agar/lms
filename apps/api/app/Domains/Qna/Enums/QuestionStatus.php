<?php

declare(strict_types=1);

namespace App\Domains\Qna\Enums;

/**
 * Lifecycle of a course question.
 *
 *   Open      — asked, nobody has replied yet. This is what the SLA clock runs against.
 *   Answered  — somebody has replied, but the asker has not said it solved anything.
 *   Resolved  — the asker accepted an answer. (Kept under its original name rather than renamed to
 *               "accepted": rows, factories and the search index already carry this value, and a
 *               rename would rewrite history for no gain.)
 *   Closed    — the course team ended the thread without an accepted answer: a duplicate, an
 *               off-topic question, or one overtaken by a course update.
 *   Hidden    — a moderation outcome, removed from the learner-facing list without hard-deleting.
 */
enum QuestionStatus: string
{
    case Open = 'open';
    case Answered = 'answered';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Hidden = 'hidden';

    /** Visible in the ordinary learner-facing listing (hidden questions are moderation-only). */
    public function isPubliclyVisible(): bool
    {
        return $this !== self::Hidden;
    }

    /**
     * Still awaiting the course team. A closed or resolved thread is finished business; an answered
     * one has had its reply. Only these states belong in an instructor's queue.
     */
    public function awaitsResponse(): bool
    {
        return $this === self::Open;
    }

    /** Statuses a learner may filter the public list by. */
    public function isFilterable(): bool
    {
        return $this !== self::Hidden;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
