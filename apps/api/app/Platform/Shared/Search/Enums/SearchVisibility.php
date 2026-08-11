<?php

declare(strict_types=1);

namespace App\Platform\Shared\Search\Enums;

/**
 * Access class of an indexed chunk. Determines which audience a hit may be returned to:
 *   - Public        — anonymous catalogue search (published, publicly-visible content only).
 *   - Authenticated — knowledge search for signed-in, authorised users (lesson text, accepted Q&A).
 *   - Private       — never indexed by adapters and never returned; present only as a defensive
 *                     filter value so a stray private row can never leak.
 *
 * Adapters MUST NOT emit chunks for unpublished content, private user data, grades, payments or
 * secrets. This enum classifies the audience for content that is already safe to index.
 */
enum SearchVisibility: string
{
    case Public = 'public';
    case Authenticated = 'authenticated';
    case Private = 'private';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
