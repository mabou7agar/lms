<?php

declare(strict_types=1);

namespace App\Domains\Qna\Actions;

use App\Domains\Qna\Models\QuestionAnswer;
use App\Platform\Shared\Html\HtmlSanitizer;

/**
 * Edits an answer's body. Ownership (author-only) is enforced by QuestionAnswerPolicy::update before
 * this runs. `body` is re-sanitized on the write path; is_instructor / accepted are immutable here.
 */
final class UpdateAnswerAction
{
    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    public function execute(QuestionAnswer $answer, string $body): QuestionAnswer
    {
        $answer->body = $this->sanitizer->sanitize($body);
        $answer->save();

        return $answer;
    }
}
