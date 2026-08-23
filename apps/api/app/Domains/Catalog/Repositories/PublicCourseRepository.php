<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Repositories;

use App\Domains\Catalog\Models\Course;
use Illuminate\Support\Str;

/** Read repository for a public course detail route keyed by UUID or its stable slug. */
final class PublicCourseRepository
{
    public function findByIdentifier(string $identifier): ?Course
    {
        return Course::query()
            ->published()
            ->visible()
            ->with(['level', 'language', 'categories', 'tags', 'trainerLinks'])
            ->where(Str::isUuid($identifier) ? 'public_id' : 'slug', $identifier)
            ->first();
    }
}
