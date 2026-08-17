<?php

declare(strict_types=1);

namespace App\Domains\Qna\Services;

use App\Domains\Qna\Models\CourseQuestion;
use App\Domains\Qna\Models\QnaSetting;
use App\Platform\Shared\Catalog\Contracts\CourseLookupPort;
use App\Platform\Shared\Notifications\Contracts\ExpiryNotificationPort;
use Illuminate\Support\Carbon;

/**
 * Tells a course team when a learner has been waiting longer than the platform promised on their
 * behalf.
 *
 * The promise is the admin's `response_sla_hours`, and `notify_instructor_on_overdue` decides
 * whether it is worth interrupting anyone about — a setting that existed but had nothing behind it
 * until now, so the metric said "overdue" and nobody was told.
 *
 * Nothing is tracked here about what has been sent. Each notice is deduplicated on
 * (question, recipient), so a nightly sweep that keeps finding the same unanswered question keeps
 * finding it silently: a question breaches its promise once, and repeating that every night would
 * train the team to ignore the whole channel.
 */
class OverdueQuestionNotifier
{
    public function __construct(
        private readonly ExpiryNotificationPort $notifications,
        private readonly CourseLookupPort $courses,
    ) {}

    /** Run one sweep. Returns how many notices were dispatched. */
    public function sweep(?Carbon $now = null): int
    {
        $settings = QnaSetting::current();

        if (! $settings->notify_instructor_on_overdue) {
            return 0;
        }

        $now = $now ?? Carbon::now();
        $slaHours = $settings->response_sla_hours;
        $sent = 0;

        // Titles are looked up once per course rather than once per question: a backlog on one
        // course is the normal shape of this query.
        $titles = [];

        foreach (CourseQuestion::query()->overdue($slaHours, $now)->with([])->cursor() as $question) {
            $courseId = (int) $question->course_id;
            $titles[$courseId] ??= $this->courses->courseTitle($courseId) ?? '';

            $hoursWaiting = $question->created_at === null
                ? $slaHours
                : (int) floor(($now->getTimestamp() - $question->created_at->getTimestamp()) / 3600);

            foreach ($this->courses->trainerUserIds($courseId) as $userId) {
                // Counts notices actually created, not questions found: the port dedups, so a repeat
                // sweep over the same backlog must report nothing new rather than report it again.
                $created = $this->notifications->qnaQuestionOverdue(
                    recipientUserId: $userId,
                    questionRef: (string) $question->public_id,
                    questionTitle: (string) $question->title,
                    courseTitle: $titles[$courseId],
                    hoursWaiting: $hoursWaiting,
                );

                if ($created) {
                    $sent++;
                }
            }
        }

        return $sent;
    }
}
