<?php

declare(strict_types=1);

namespace App\Domains\Qna\Search;

use App\Domains\Qna\Enums\QuestionStatus;
use App\Domains\Qna\Models\CourseQuestion;
use App\Domains\Qna\Models\QuestionAnswer;
use App\Platform\Shared\Search\Contracts\IndexableContentPort;
use App\Platform\Shared\Search\Data\IndexableChunk;
use App\Platform\Shared\Search\Enums\SearchSourceType;
use App\Platform\Shared\Search\Enums\SearchVisibility;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Q&A's IndexableContentPort: exposes ACCEPTED answers (the community-validated knowledge) to the
 * search index as AUTHENTICATED-audience content. Imports only Q&A's own models + the Shared contract
 * (Deptrac: Qna -> Shared). The owning course's published state is checked via a raw `courses` lookup
 * (no Catalog model import), and the tenant is read from that same course row.
 *
 * Only ACCEPTED answers to non-hidden questions on PUBLISHED courses are indexed. Unaccepted answers,
 * hidden/moderated questions, and answers on unpublished courses are never indexed. Bodies are
 * stripped of HTML before indexing.
 */
final class AcceptedAnswerIndexableContentAdapter implements IndexableContentPort
{
    public function sourceType(): string
    {
        return SearchSourceType::Qna->value;
    }

    public function indexableIds(): array
    {
        return QuestionAnswer::query()
            ->where('question_answers.accepted', true)
            ->join('course_questions', 'course_questions.id', '=', 'question_answers.question_id')
            ->join('courses', 'courses.id', '=', 'course_questions.course_id')
            ->where('course_questions.status', '!=', QuestionStatus::Hidden->value)
            ->where('courses.status', 'published')
            ->orderBy('question_answers.id')
            ->pluck('question_answers.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function chunksFor(int $id): array
    {
        $answer = QuestionAnswer::query()->with('question')->whereKey($id)->first();

        if ($answer === null || $answer->getAttribute('accepted') !== true) {
            return [];
        }

        $question = $answer->getRelation('question');
        if (! $question instanceof CourseQuestion || $question->getAttribute('status') === QuestionStatus::Hidden) {
            return [];
        }

        // Resolve the owning course's tenant + published state WITHOUT importing the Catalog model.
        // The question carries no persisted organization_id (course-inherited tenancy), so the course
        // row is the authoritative source — mirroring the lesson adapter.
        $course = DB::table('courses')->where('id', $question->getAttribute('course_id'))->first(['organization_id', 'status']);
        if ($course === null || $course->status !== 'published') {
            return [];
        }

        $title = $this->stringOrNull($question->getAttribute('title'));
        $body = trim(strip_tags((string) $answer->getAttribute('body')));
        $text = trim(($title ?? '').' '.$body);

        if ($text === '') {
            return [];
        }

        $updatedAt = $answer->getAttribute('updated_at');

        return [new IndexableChunk(
            embeddableType: 'qna_answer',
            embeddableId: $id,
            embeddablePublicId: (string) $answer->getAttribute('public_id'),
            organizationId: is_numeric($course->organization_id) ? (int) $course->organization_id : null,
            locale: '*',
            sourceType: SearchSourceType::Qna,
            visibility: SearchVisibility::Authenticated,
            title: $title,
            chunkText: $text,
            version: $updatedAt instanceof DateTimeInterface ? $updatedAt->getTimestamp() : 1,
            chunkIndex: 0,
            courseId: is_numeric($question->getAttribute('course_id')) ? (int) $question->getAttribute('course_id') : null,
        )];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
