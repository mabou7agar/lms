<?php

namespace App\Domains\Authoring\Enums;

/**
 * P2/W03 - Why a content version row exists. Drives the DB CHECK constraint and the
 * source-version rule (rollback/clone/fork MUST carry a source; manual/safety MUST NOT).
 */
enum VersionReason: string
{
    case Manual = 'manual';     // explicit snapshot of the current draft
    case Safety = 'safety';     // auto snapshot taken before a restore replaces the draft
    case Rollback = 'rollback'; // new current version created from an older one
    case Clone = 'clone';       // copy within the same course
    case Fork = 'fork';         // draft materialised into another course

    /** Reasons that require a source_version_id. */
    public function requiresSource(): bool
    {
        return match ($this) {
            self::Rollback, self::Clone, self::Fork => true,
            self::Manual, self::Safety => false,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}
