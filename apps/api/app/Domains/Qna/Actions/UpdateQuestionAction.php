<?php

declare(strict_types=1);

namespace App\Domains\Qna\Actions;

use App\Domains\Qna\Models\CourseQuestion;
use App\Platform\Shared\Html\HtmlSanitizer;

/**
 * Edits a question's user-authored fields. Ownership (author-only) is enforced by
 * CourseQuestionPolicy::update before this runs. `body` is re-sanitized on the write path.
 * organization_id / course_id / status are never editable here.
 */
final class UpdateQuestionAction
{
    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    /** @param  array<string, mixed>  $data */
    public function execute(CourseQuestion $question, array $data): CourseQuestion
    {
        if (array_key_exists('title', $data)) {
            $question->title = (string) $data['title'];
        }

        if (array_key_exists('body', $data)) {
            $question->body = $this->sanitizer->sanitize((string) $data['body']);
        }

        if (array_key_exists('lesson_timestamp_seconds', $data)) {
            $question->lesson_timestamp_seconds = $data['lesson_timestamp_seconds'];
        }

        $question->save();

        return $question;
    }
}
