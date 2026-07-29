<?php

namespace App\Contexts\Learning\Exceptions;

/**
 * A progress update that cannot physically be true — e.g. a video position past the asset's
 * duration, or a negative timestamp. Distinct from an access failure: the learner may see the
 * lesson, but the payload is impossible, so it is rejected rather than clamped silently.
 */
class InvalidProgressException extends LearningException
{
    protected string $errorCode = 'LEARNING_INVALID_PROGRESS';

    protected int $status = 422;

    public function __construct(string $message = 'The progress update is not valid for this lesson.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
