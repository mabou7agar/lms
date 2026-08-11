<?php

declare(strict_types=1);

namespace App\Platform\Shared\Search\Enums;

/**
 * The kind of content a search/embedding row was derived from. Purely a classification for
 * filtering and attribution — the owning domain decides which of its records map to which type.
 */
enum SearchSourceType: string
{
    case Course = 'course';
    case Lesson = 'lesson';
    case Qna = 'qna';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
