<?php

namespace App\Domains\Catalog\Enums;

enum CourseStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
    // Lifecycle states (additive): an editorial review pipeline plus scheduled and unpublished
    // states. NONE of these are publicly visible — only Published is (see isPubliclyVisible()).
    case Review = 'review';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Unpublished = 'unpublished';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isPublished(): bool
    {
        return $this === self::Published;
    }

    /**
     * The single visibility gate the catalog trusts: TRUE only for Published. Draft, Review,
     * Approved, Scheduled, Unpublished and Archived are all non-public — mirroring
     * Course::scopePublished(), which filters on Published alone.
     */
    public function isPubliclyVisible(): bool
    {
        return $this === self::Published;
    }

    /** Filament badge colour for this status. */
    public function color(): string
    {
        return match ($this) {
            self::Published => 'success',
            self::Draft, self::Unpublished => 'gray',
            self::Review => 'warning',
            self::Approved, self::Scheduled => 'info',
            self::Archived => 'danger',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
