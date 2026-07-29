<?php

namespace App\Platform\Shared\Learning\Contracts;

use App\Contexts\Learning\Support\NullLessonAvailabilityPort;
use Carbon\CarbonInterface;

/**
 * Cross-context port DECLARED by Learning for DRIP release scheduling. Implemented by the context
 * that owns the schedule (Authoring/Catalog); Learning only consumes it.
 *
 * Returns the SERVER-side release instant for each lesson, computed relative to the learner's
 * enrollment instant (for enrollment-relative drip) or as an absolute date (for fixed-date drip).
 * A null value means "available immediately" (no drip). Learning compares the returned instant to
 * `now()` — the drip decision is always made against server time, never a client-supplied clock.
 *
 * Batched by design: one call resolves a whole curriculum so the runtime curriculum view never
 * issues a query per lesson. Boundary-safe: scalar ids in, timestamps out, no Eloquent, no throw.
 * Default binding is {@see NullLessonAvailabilityPort} (everything
 * available immediately), so drip is inert until a real schedule provider is bound.
 */
interface LessonAvailabilityPort
{
    /**
     * Release instant per lesson id. Every supplied id SHOULD appear in the result; a missing key
     * or a null value is treated by Learning as "available immediately".
     *
     * @param  list<int>  $lessonIds
     * @return array<int, ?CarbonInterface>
     */
    public function releaseAtForLessons(array $lessonIds, CarbonInterface $enrolledAt): array;
}
