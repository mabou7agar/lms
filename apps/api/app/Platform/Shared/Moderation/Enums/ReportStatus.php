<?php

namespace App\Platform\Shared\Moderation\Enums;

/**
 * Lifecycle of a content report. `pending` is the open state (a report is "open" while pending);
 * the other three are terminal resolutions written by a moderator through ModerationService.
 */
enum ReportStatus: string
{
    case Pending = 'pending';
    case Reviewed = 'reviewed';
    case Dismissed = 'dismissed';
    case Actioned = 'actioned';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $s): string => $s->value, self::cases());
    }
}
