<?php

namespace App\Contexts\Learning\Exceptions;

/**
 * Raised when someone tries to self-enrol into a course that is sold commercially. Access to such a
 * course only ever arrives through a fulfilled order or a company/manager grant, never through the
 * payment-free enrol endpoint.
 */
class CoursePurchaseRequiredException extends LearningException
{
    protected string $errorCode = 'LEARNING_COURSE_PURCHASE_REQUIRED';

    protected int $status = 402;

    public function __construct(string $message = 'This course must be purchased before you can enrol.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
