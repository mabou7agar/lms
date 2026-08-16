<?php

declare(strict_types=1);

namespace App\Domains\Authoring\Enums;

/**
 * Who may download a course resource.
 *
 * `Enrolled` is the default and the honest one: the file is part of what the course sells, so it
 * follows the same entitlement as the lessons — including a company seat's expiry, which is why the
 * check is made at download time rather than baked into a long-lived link.
 *
 * `Preview` is an explicit decision to give a file away — a sample chapter, a syllabus — and is the
 * only way an unentitled visitor reaches one.
 */
enum ResourceVisibility: string
{
    case Enrolled = 'enrolled';
    case Preview = 'preview';

    public function requiresEntitlement(): bool
    {
        return $this === self::Enrolled;
    }

    public function label(): string
    {
        return match ($this) {
            self::Enrolled => 'Enrolled learners only',
            self::Preview => 'Anyone (free preview)',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $v): string => $v->value, self::cases());
    }
}
