<?php

namespace App\Contexts\Commerce\Enums;

/**
 * When a company may move an assigned seat from one employee to another. Reclaiming a seat after the
 * previous holder has made progress destroys their record, so the stricter options exist to stop a
 * licence being recycled through a whole team.
 */
enum SeatReassignmentPolicy: string
{
    case Always = 'always';
    case BeforeStart = 'before_start';
    case BeforeProgressThreshold = 'before_progress_threshold';
    case Never = 'never';

    public function label(): string
    {
        return match ($this) {
            self::Always => 'Any time',
            self::BeforeStart => 'Only before the employee starts the course',
            self::BeforeProgressThreshold => 'Only below a progress threshold',
            self::Never => 'Never — a seat stays with its first holder',
        };
    }

    /** True when the numeric progress threshold is meaningful. */
    public function needsThreshold(): bool
    {
        return $this === self::BeforeProgressThreshold;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
