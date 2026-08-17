<?php

namespace App\Platform\Shared\Support;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Recognises "the caller handed us an identifier the database cannot even represent".
 *
 * `public_id` is a Postgres `uuid` column. Comparing it to `not-a-uuid` does not return zero rows —
 * it raises SQLSTATE 22P02 (invalid_text_representation) and the request dies as a 500. Implicit
 * route-model binding has always guarded that; the ~70 lookups that read `public_id` directly did
 * not, so any crawler, scanner or mistyped link produced a server error and an error-tracker alert
 * for something that is simply not found.
 *
 * Guarding each of those call sites one by one is the prevention, and worth doing over time. This is
 * the net underneath it, deliberately narrow: ONLY 22P02, and ONLY when the failing statement was
 * looking at a `public_id`. A malformed integer in an admin form, a bad enum cast or any other
 * 22P02 keeps its 500 and stays visible as the bug it is.
 */
final class MalformedIdentifier
{
    /** Postgres: invalid text representation — the value is not of the column's type at all. */
    private const INVALID_TEXT_REPRESENTATION = '22P02';

    public static function causedBy(Throwable $e): bool
    {
        if (! $e instanceof QueryException) {
            return false;
        }

        $state = $e->errorInfo[0] ?? null;

        if ($state !== self::INVALID_TEXT_REPRESENTATION) {
            return false;
        }

        // The SQL, not the message: the driver's wording varies, the column name does not.
        return str_contains(strtolower($e->getSql()), 'public_id');
    }
}
