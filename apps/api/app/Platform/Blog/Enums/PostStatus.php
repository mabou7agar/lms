<?php

namespace App\Platform\Blog\Enums;

/**
 * Editorial lifecycle of a blog post. Only `Published` posts (and only while within their scheduled
 * window) are served on the public endpoint; every other state is admin-only. The scope
 * `BlogPost::published()` combines this status with the published_at / unpublished_at window.
 */
enum PostStatus: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Review => 'In review',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }

    /** @return array<string, string> value => label, for Filament selects. */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
