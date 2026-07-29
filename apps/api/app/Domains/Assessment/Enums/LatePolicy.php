<?php

namespace App\Domains\Assessment\Enums;

/**
 * How a submission after `due_at` is handled.
 *
 *  - blocked:   the submit is rejected outright once the due date has passed.
 *  - allowed:   accepted and flagged `is_late`, but scored normally.
 *  - penalised: accepted, flagged late, and the awarded score is reduced by the assignment's
 *               `late_penalty_percent` at grade time.
 */
enum LatePolicy: string
{
    case Blocked = 'blocked';
    case Allowed = 'allowed';
    case Penalised = 'penalised';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }
}
