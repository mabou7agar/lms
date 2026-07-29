<?php

namespace App\Contexts\Learning\Exceptions;

/**
 * An explicit "complete this lesson" request that the server refuses because an outstanding
 * requirement is unmet — a required assignment not yet accepted, a required block not yet done, or
 * a required video not yet watched to threshold. Completion is server-decided; the client cannot
 * override it. `details.reasons` lists the unmet requirement codes.
 */
class LessonCompletionBlockedException extends LearningException
{
    protected string $errorCode = 'LEARNING_COMPLETION_BLOCKED';

    protected int $status = 422;

    /** @param list<string> $reasons */
    public static function withReasons(array $reasons): self
    {
        return new self(
            'This lesson cannot be completed yet: outstanding requirements remain.',
            ['reasons' => $reasons],
        );
    }

    public function __construct(string $message = 'This lesson cannot be completed yet.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
