<?php

namespace App\Contexts\Learning\Exceptions;

/**
 * The learner IS enrolled, but the access window on that enrollment has closed — in practice a seat
 * from a company purchase that has run out. Distinct from NotEnrolledException on purpose: "your
 * company's access to this course has ended" is a different thing to tell someone than "you are not
 * enrolled", and only one of them is fixed by talking to their manager.
 */
class EnrollmentExpiredException extends LearningException
{
    protected string $errorCode = 'LEARNING_ACCESS_EXPIRED';

    protected int $status = 403;

    public function __construct(string $message = 'Access to this course has ended.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
