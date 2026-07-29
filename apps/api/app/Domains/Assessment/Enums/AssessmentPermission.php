<?php

namespace App\Domains\Assessment\Enums;

/**
 * Global (non-ownership) permissions for the Assessment domain.
 *
 * Mirrors the Authoring lesson from Step 2: these are granted to the `admin` role ONLY. Instructors
 * are authorized by OWNERSHIP through the `assessment.manage-assessment` gate, never by holding a global
 * permission — granting the permission to every instructor would defeat ownership scoping.
 */
enum AssessmentPermission: string
{
    case Manage = 'assessment.manage';
    case ViewResults = 'assessment.results.view';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }
}
