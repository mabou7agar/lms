<?php

namespace App\Platform\Shared\Notifications\Contracts;

/**
 * Learning-flow notification port DECLARED in Shared and IMPLEMENTED by the Notifications platform
 * capability. It lets a producing domain (Assessment / Q&A / Forum) request a learner-facing
 * notification WITHOUT importing the Notifications context: the domain speaks only these scalar,
 * intent-named methods; the implementation owns category, template key, channel selection and the
 * deterministic dedup key.
 *
 * Boundary-safe by construction — scalar ids only, no Eloquent, no throwing. Because the domains
 * depend on Shared and Notifications depends on Shared, wiring these flows adds NO
 * domain<->Notifications Deptrac edge (the Notifications subscriber's direct event imports are only
 * tolerated via the baseline, which these new domains are deliberately kept out of).
 */
interface LearningNotificationPort
{
    /** A released assignment grade became visible to the learner. Dedup per submission. */
    public function assignmentGradeReleased(int $learnerUserId, int $submissionId): void;

    /** A grader asked the learner to revise and resubmit. Dedup per submission. */
    public function assignmentChangesRequested(int $learnerUserId, int $submissionId): void;

    /** A graded quiz attempt reached a pass/fail outcome. Dedup per attempt. */
    public function assessmentGraded(int $learnerUserId, int $attemptId, bool $passed): void;

    /** A new answer was posted to the learner's question. Dedup per answer. */
    public function questionAnswered(int $questionAuthorId, int $answerId): void;

    /** A reply was posted to the recipient's forum thread. Dedup per (post, recipient). */
    public function forumReply(int $recipientUserId, int $postId): void;

    /** The recipient was @mentioned in a forum post. Dedup per (post, recipient). */
    public function forumMention(int $recipientUserId, int $postId): void;
}
