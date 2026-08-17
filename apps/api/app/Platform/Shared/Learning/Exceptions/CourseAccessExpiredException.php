<?php

namespace App\Platform\Shared\Learning\Exceptions;

/**
 * The caller's entitlement to this course has run out.
 *
 * Distinct from CourseAccessDeniedException because the remedy is different and the client is
 * expected to say so: renew or ask your manager, not "you need to buy this". It carries the same
 * 403 so nothing about existing status handling changes — the code is what tells them apart.
 *
 * It deliberately reuses Learning's existing `LEARNING_ACCESS_EXPIRED` rather than minting a second
 * string. This is one condition — the learner's window closed — and the player already branches on
 * that code. Two codes for one condition would mean every client had to know both, which is the
 * problem this wave exists to remove.
 */
class CourseAccessExpiredException extends CourseAccessDeniedException
{
    protected string $errorCode = 'LEARNING_ACCESS_EXPIRED';

    public function __construct(string $message = 'Your access to this course has ended.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
