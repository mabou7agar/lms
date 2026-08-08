<?php

declare(strict_types=1);

namespace App\Domains\Qna\Listeners;

use App\Domains\Qna\Events\QuestionAnswered;
use App\Platform\Shared\Notifications\Contracts\LearningNotificationPort;
use Illuminate\Events\Dispatcher;

/**
 * Notifies a question's author when a NEW answer is posted, via the Shared
 * {@see LearningNotificationPort}. The self-answer case (an author answering their own question) is
 * skipped. Lives in the Qna layer so it depends only on Shared — no Notifications<->Qna Deptrac edge.
 * Dedup is per answer (owned by the port).
 */
final class QnaNotificationSubscriber
{
    public function __construct(private readonly LearningNotificationPort $notifications) {}

    public function onQuestionAnswered(QuestionAnswered $event): void
    {
        // Don't notify yourself for answering your own question.
        if ($event->answerAuthorId === $event->questionAuthorId) {
            return;
        }

        $this->notifications->questionAnswered($event->questionAuthorId, $event->answerId);
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            QuestionAnswered::class => 'onQuestionAnswered',
        ];
    }
}
