<?php

namespace App\Domains\Assessment\Listeners;

use App\Domains\Assessment\Events\AssignmentChangesRequested;
use App\Domains\Assessment\Events\AssignmentGradeReleased;
use App\Domains\Assessment\Events\AttemptGraded;
use App\Platform\Shared\Notifications\Contracts\LearningNotificationPort;
use Illuminate\Events\Dispatcher;

/**
 * Turns Assessment producer events into learner notifications through the Shared
 * {@see LearningNotificationPort}. It lives in the Assessment layer (not the Notifications subscriber)
 * on purpose: it depends only on Shared, so no Notifications<->Assessment Deptrac edge is introduced.
 * The port owns the notification category, template key and the deterministic dedup key.
 */
class AssessmentNotificationSubscriber
{
    public function __construct(private readonly LearningNotificationPort $notifications) {}

    public function onAssignmentGradeReleased(AssignmentGradeReleased $event): void
    {
        $this->notifications->assignmentGradeReleased($event->userId, $event->submissionId);
    }

    public function onAssignmentChangesRequested(AssignmentChangesRequested $event): void
    {
        $this->notifications->assignmentChangesRequested($event->userId, $event->submissionId);
    }

    public function onAttemptGraded(AttemptGraded $event): void
    {
        $this->notifications->assessmentGraded($event->learnerUserId, $event->attemptId, $event->passed);
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            AssignmentGradeReleased::class => 'onAssignmentGradeReleased',
            AssignmentChangesRequested::class => 'onAssignmentChangesRequested',
            AttemptGraded::class => 'onAttemptGraded',
        ];
    }
}
