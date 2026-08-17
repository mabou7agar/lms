<?php

declare(strict_types=1);

namespace App\Domains\Qna\Actions;

use App\Domains\Qna\Enums\QuestionStatus;
use App\Domains\Qna\Enums\QuestionVisibility;
use App\Domains\Qna\Models\CourseQuestion;
use App\Domains\Qna\Support\ResolvedCourse;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Shared\Analytics\AnalyticsEventName;
use App\Platform\Shared\Analytics\Contracts\AnalyticsEventRecorder;
use App\Platform\Shared\Analytics\Data\AnalyticsEventInput;
use App\Platform\Shared\Html\HtmlSanitizer;
use App\Platform\Shared\Learning\Contracts\CourseEnrollmentPort;
use App\Platform\Shared\Learning\Support\CourseAccessGuard;

/**
 * Posts a new question to a course. The author must have course ACCESS — an enrolled/entitled learner
 * (active OR completed) or a course instructor. The check runs here, at the domain entry point, the
 * same way StartAttemptAction gates attempts, so no authenticated non-participant can seed questions
 * onto a course by public_id.
 *
 * `body` is sanitized on the way IN (never on read). `organization_id` is stamped SERVER-SIDE from
 * the resolved course and is not mass-assignable — a forged organization_id in the payload is inert.
 */
final class AskQuestionAction
{
    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly CourseEnrollmentPort $enrollment,
        private readonly CourseAccessPort $access,
        private readonly AnalyticsEventRecorder $analytics,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function execute(Actor $author, ResolvedCourse $course, array $data): CourseQuestion
    {
        $userId = $author->actorId();

        if (! $this->access->canManageContent($author, $course->id)) {
            (new CourseAccessGuard($this->enrollment))->assert($course->id, $userId);
        }

        $question = new CourseQuestion([
            'course_id' => $course->id,
            'lesson_id' => $data['lesson_id'] ?? null,
            'user_id' => $userId,
            'title' => $data['title'],
            'body' => $this->sanitizer->sanitize((string) $data['body']),
            // Public unless the asker chose otherwise: a course Q&A that defaults to private stops
            // being a Q&A and becomes a support queue nobody else learns from.
            'visibility' => $data['visibility'] ?? QuestionVisibility::Public->value,
            'lesson_timestamp_seconds' => $data['lesson_timestamp_seconds'] ?? null,
        ]);

        // Transitive tenancy stamp — a direct attribute write, deliberately outside $fillable.
        $question->organization_id = $course->organizationId;
        // Set the initial status in-memory so the freshly-created instance (never round-tripped
        // through the DB) carries the enum the resource serializes; mirrors the DB column default.
        $question->status = QuestionStatus::Open;
        $question->save();

        $this->analytics->record(new AnalyticsEventInput(
            name: AnalyticsEventName::QnaQuestionAsked->value,
            userId: $userId,
            organizationId: $course->organizationId,
            courseId: $course->id,
            metadata: ['scope' => $question->lesson_id === null ? 'course' : 'lesson'],
            dedupKey: 'qna_asked:'.$question->public_id,
        ));

        return $question;
    }
}
