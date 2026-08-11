<?php

declare(strict_types=1);

namespace App\Platform\AI\Governance;

use App\Platform\AI\Exceptions\AiFeatureDisabledException;

/**
 * Documented guardrail: AI in this platform MUST NOT issue an automatic FINAL grade or
 * pass/fail decision on a learner's assessment. AI may draft feedback, suggest rubric notes, or
 * assist an instructor, but the authoritative grade of record is always set by a human.
 *
 * This is enforced structurally: no AI feature is wired into the Assessment grading write-path, and
 * any caller that would use an AI result AS a final grade must first pass it through
 * assertNotFinalGrading(), which fails closed. The flag is a compile-time reminder of the policy.
 */
final class GradingPolicy
{
    /** AI is never permitted to set the authoritative grade of record. */
    public const ALLOWS_AUTOMATIC_FINAL_GRADING = false;

    /**
     * Fail closed if an AI output is about to be used as a final grade.
     *
     * @param  bool  $usedAsFinalGrade  true when the caller intends this AI output to BE the grade
     */
    public function assertNotFinalGrading(bool $usedAsFinalGrade): void
    {
        if ($usedAsFinalGrade) {
            throw new AiFeatureDisabledException(
                'grading',
                'AI must not set a final grade — automatic final grading is prohibited by policy.'
            );
        }
    }
}
