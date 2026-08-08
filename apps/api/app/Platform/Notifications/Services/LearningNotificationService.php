<?php

namespace App\Platform\Notifications\Services;

use App\Platform\Notifications\Enums\NotificationCategory;
use App\Platform\Shared\Notifications\Contracts\LearningNotificationPort;

/**
 * The Learning-flow implementation of {@see LearningNotificationPort}. It maps each domain intent to a
 * notification category + template key + deterministic dedup key and routes everything through the
 * shared {@see NotificationDispatcher} — locale, consent/preferences, deduplication and the queued
 * delivery path are all handled there (no inline sends). Producing domains reach this behaviour only
 * through the Shared port and never see the Notifications context.
 *
 * Every learning flow is classified under {@see NotificationCategory::Learning} so a single
 * per-category preference opt-out governs the whole set, matching the existing enrollment/completion
 * wirings in {@see \App\Platform\Notifications\Listeners\NotificationEventSubscriber}.
 */
class LearningNotificationService implements LearningNotificationPort
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function assignmentGradeReleased(int $learnerUserId, int $submissionId): void
    {
        $this->dispatcher->dispatchToUserId(
            $learnerUserId,
            NotificationCategory::Learning,
            'assignment_graded',
            [],
            null,
            'assignment-graded:'.$submissionId,
        );
    }

    public function assignmentChangesRequested(int $learnerUserId, int $submissionId): void
    {
        $this->dispatcher->dispatchToUserId(
            $learnerUserId,
            NotificationCategory::Learning,
            'assignment_changes_requested',
            [],
            null,
            'assignment-changes-requested:'.$submissionId,
        );
    }

    public function assessmentGraded(int $learnerUserId, int $attemptId, bool $passed): void
    {
        // One dedup key per attempt (not per outcome): an attempt has a single pass/fail result, so a
        // re-scored/retried AttemptGraded resolves to the same notification instead of sending twice.
        $this->dispatcher->dispatchToUserId(
            $learnerUserId,
            NotificationCategory::Learning,
            $passed ? 'assessment_passed' : 'assessment_failed',
            [],
            null,
            'assessment-graded:'.$attemptId,
        );
    }

    public function questionAnswered(int $questionAuthorId, int $answerId): void
    {
        $this->dispatcher->dispatchToUserId(
            $questionAuthorId,
            NotificationCategory::Learning,
            'qna_answered',
            [],
            null,
            'qna-answered:'.$answerId,
        );
    }

    public function forumReply(int $recipientUserId, int $postId): void
    {
        $this->dispatcher->dispatchToUserId(
            $recipientUserId,
            NotificationCategory::Learning,
            'forum_reply',
            [],
            null,
            'forum-reply:'.$postId.':user:'.$recipientUserId,
        );
    }

    public function forumMention(int $recipientUserId, int $postId): void
    {
        $this->dispatcher->dispatchToUserId(
            $recipientUserId,
            NotificationCategory::Learning,
            'forum_mention',
            [],
            null,
            'forum-mention:'.$postId.':user:'.$recipientUserId,
        );
    }
}
