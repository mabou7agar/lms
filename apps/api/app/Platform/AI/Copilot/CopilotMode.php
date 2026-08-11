<?php

declare(strict_types=1);

namespace App\Platform\AI\Copilot;

/**
 * The suggestion the INSTRUCTOR COPILOT is asked to produce. Every mode is advisory only — the
 * copilot drafts text for the instructor to accept/edit; it never writes to a learner's record and
 * never grades. The mode is turned into an instruction line for the `copilot.assist` prompt.
 */
enum CopilotMode: string
{
    /** Draft or improve the wording of a lesson / course copy from the instructor's brief. */
    case DraftLesson = 'draft_lesson';

    /** Summarize the recurring themes in the course's learner questions (Q&A). */
    case SummarizeQuestions = 'summarize_questions';

    /** Suggest what content to add or teach next, grounded in the existing curriculum. */
    case SuggestContent = 'suggest_content';

    /** The human instruction handed to the prompt for this mode. */
    public function directive(): string
    {
        return match ($this) {
            self::DraftLesson => 'Draft or improve lesson copy for the instructor based on their brief. '
                .'Return polished, ready-to-edit copy — a suggestion only.',
            self::SummarizeQuestions => 'Summarize the recurring themes and gaps in the learners\' questions '
                .'for this course, so the instructor knows what to clarify. Do not answer on their behalf.',
            self::SuggestContent => 'Suggest what the instructor could teach or add next, grounded in the '
                .'existing course material. Provide options, not a mandate.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
