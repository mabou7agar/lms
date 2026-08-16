<?php

declare(strict_types=1);

namespace App\Domains\Authoring\Actions;

use App\Domains\Authoring\Models\CourseResource;

/**
 * Writing course resources. Authorization and lesson/media resolution stay in the controller, which
 * is where the request lives; this owns the persistence so a controller never does.
 *
 * Detaching is a soft delete on purpose: unpublishing a file should not destroy the record of what a
 * course once offered, and the library asset it points at is untouched either way.
 *
 * The methods are named for what they do to a COURSE — attach, revise, detach — rather than for the
 * database verbs underneath, which is both truer to the domain and keeps the "no persistence verbs
 * in a controller" rule meaningful at the call site.
 */
final class ManageCourseResourceAction
{
    /** @param array<string, mixed> $attributes */
    public function attach(array $attributes): CourseResource
    {
        return CourseResource::create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function revise(CourseResource $resource, array $attributes): CourseResource
    {
        $resource->fill($attributes)->save();

        return $resource->refresh();
    }

    public function detach(CourseResource $resource): void
    {
        $resource->delete();
    }
}
