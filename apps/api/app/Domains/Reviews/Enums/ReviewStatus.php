<?php

namespace App\Domains\Reviews\Enums;

/**
 * Publication state of a course review. New reviews are `published` immediately (no pre-moderation);
 * a moderator may later `hidden` or `rejected` a review, which removes it from the aggregate.
 */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Rejected = 'rejected';
    case Hidden = 'hidden';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isVisible(): bool
    {
        return $this === self::Published;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $s): string => $s->value, self::cases());
    }
}
