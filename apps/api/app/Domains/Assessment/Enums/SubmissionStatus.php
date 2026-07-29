<?php

namespace App\Domains\Assessment\Enums;

/**
 * Lifecycle of one learner submission attempt.
 *
 * `draft` is the ONLY non-terminal working state (the partial-unique index guarantees one per
 * learner/assignment). `submitted`/`late`/`under_review`/`changes_requested` are in-flight review
 * states. `graded`/`returned`/`cancelled` are terminal for that attempt — a resubmission opens a
 * NEW attempt row rather than mutating a terminal one.
 */
enum SubmissionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Late = 'late';
    case UnderReview = 'under_review';
    case ChangesRequested = 'changes_requested';
    case Graded = 'graded';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    /** The single working state a learner may still edit. */
    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    /** Submitted-and-awaiting or in review — immutable to the learner, actionable by a grader. */
    public function isSubmitted(): bool
    {
        return in_array($this, [self::Submitted, self::Late, self::UnderReview], true);
    }

    /** No further grading transitions; a new attempt is required to change anything. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Graded, self::Returned, self::Cancelled], true);
    }

    /** A learner may open a fresh attempt only from one of these. */
    public function allowsResubmission(): bool
    {
        return in_array($this, [self::ChangesRequested, self::Returned], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
