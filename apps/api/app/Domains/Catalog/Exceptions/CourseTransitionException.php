<?php

namespace App\Domains\Catalog\Exceptions;

use App\Domains\Catalog\Enums\CourseStatus;

/**
 * Raised when a caller asks the CourseLifecycle state machine for a transition the legal-transition
 * map does not permit (e.g. Published -> Review), or hands an invalid argument for a legal one
 * (e.g. a Scheduled transition with a past/absent publish time). Renders as the standard 422 envelope.
 */
class CourseTransitionException extends CatalogException
{
    protected string $errorCode = 'CATALOG_COURSE_TRANSITION_ILLEGAL';

    protected int $status = 422;

    public static function illegal(CourseStatus $from, CourseStatus $to): self
    {
        return new self(
            sprintf('A course cannot move from "%s" to "%s".', $from->value, $to->value),
            ['from' => $from->value, 'to' => $to->value],
        );
    }

    public static function scheduleRequiresFutureTime(): self
    {
        return new self(
            'Scheduling a course to publish requires a publish time in the future.',
            ['field' => 'scheduled_publish_at'],
        );
    }
}
