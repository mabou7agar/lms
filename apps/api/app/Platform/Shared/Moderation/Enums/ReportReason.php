<?php

namespace App\Platform\Shared\Moderation\Enums;

/**
 * Why a piece of user-generated content was reported. Backed by stable string values persisted on
 * content_reports.reason, so any domain that adopts CanBeReported speaks the same vocabulary.
 */
enum ReportReason: string
{
    case Spam = 'spam';
    case Offensive = 'offensive';
    case Harassment = 'harassment';
    case OffTopic = 'off_topic';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Spam => 'Spam',
            self::Offensive => 'Offensive',
            self::Harassment => 'Harassment',
            self::OffTopic => 'Off-topic',
            self::Other => 'Other',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $r): string => $r->value, self::cases());
    }
}
