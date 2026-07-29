<?php

namespace App\Domains\Assessment\Enums;

/**
 * Global (non-ownership) permissions for the assignment surface of the Assessment domain.
 *
 * Granted to the `admin` role ONLY. Instructors are authorized by course OWNERSHIP through the
 * `assignment.manage-assignment` gate (course_id -> CourseAccessPort), never by holding a global
 * permission. Mirrors AssessmentPermission's design exactly.
 */
enum AssignmentPermission: string
{
    case Manage = 'assignment.manage';
    case Grade = 'assignment.grade';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }
}
