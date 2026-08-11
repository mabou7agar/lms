<?php

declare(strict_types=1);

namespace App\Platform\Integration\Emission;

use DateTimeInterface;

/**
 * Type-safe reader for values pulled off a domain event by DOTTED PATH (e.g. "enrollment.public_id").
 *
 * The emission layer must NOT import any domain event/model class (Deptrac + boundary), so it only
 * ever sees events as `object`. These helpers traverse public properties / Eloquent attributes via
 * data_get() and coerce to a concrete scalar, keeping the per-event payload mappers fully typed with
 * no property access on `mixed`.
 */
final class EventValue
{
    public static function string(object $event, string $path): ?string
    {
        $value = data_get($event, $path);

        return is_scalar($value) ? (string) $value : null;
    }

    public static function int(object $event, string $path): ?int
    {
        $value = data_get($event, $path);

        return is_numeric($value) ? (int) $value : null;
    }

    public static function bool(object $event, string $path): ?bool
    {
        $value = data_get($event, $path);

        return is_bool($value) ? $value : null;
    }

    /** ISO-8601 for a date/time value (Carbon/DateTimeInterface or an already-string timestamp). */
    public static function iso(object $event, string $path): ?string
    {
        $value = data_get($event, $path);

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return is_string($value) ? $value : null;
    }
}
