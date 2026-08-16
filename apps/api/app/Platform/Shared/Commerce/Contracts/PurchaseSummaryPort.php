<?php

namespace App\Platform\Shared\Commerce\Contracts;

use App\Platform\Shared\Commerce\Data\PurchaseSummary;

/**
 * Lets Catalog say "how is this course sold?" without importing a Commerce model. Commerce owns the
 * only implementation; the answer crosses as a DTO, and course ids cross as scalars.
 */
interface PurchaseSummaryPort
{
    /** How the course is sold, or a not-purchasable summary when no active product grants it. */
    public function forCourse(int $courseId): PurchaseSummary;

    /**
     * Summaries for many courses in one pass, keyed by course id, so a listing never issues a query
     * per row. Every supplied id appears in the result.
     *
     * @param  list<int>  $courseIds
     * @return array<int, PurchaseSummary>
     */
    public function forCourseIds(array $courseIds): array;
}
