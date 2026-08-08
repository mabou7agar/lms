<?php

declare(strict_types=1);

namespace App\Domains\Reviews\Support;

use App\Platform\Shared\Helpers\Uuid;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Resolves the small set of course scalars the Reviews domain needs (internal id, denormalized
 * organization_id for stamping, status/visibility for gating) from a course public_id, by querying
 * the `courses` TABLE by name via the query builder — never importing Catalog's Course model.
 *
 * This mirrors exactly how CourseTenantScope treats the course: as an opaque table joined by string,
 * keeping the Reviews Domain within its allowed dependencies (Shared + Identity contracts only).
 */
final class CourseLookup
{
    /** Resolve a course row by public_id, or null for an unknown/malformed id (soft-deleted excluded). */
    public function byPublicId(string $publicId): ?stdClass
    {
        // public_id is a uuid column; a non-UUID would raise a Postgres 22P02 rather than 404.
        if (! Uuid::isValid($publicId)) {
            return null;
        }

        $row = DB::table('courses')
            ->where('public_id', $publicId)
            ->whereNull('deleted_at')
            ->first(['id', 'public_id', 'organization_id', 'status', 'visibility', 'title']);

        return $row instanceof stdClass ? $row : null;
    }
}
