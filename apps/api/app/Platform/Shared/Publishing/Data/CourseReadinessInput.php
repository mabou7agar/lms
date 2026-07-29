<?php

namespace App\Platform\Shared\Publishing\Data;

/**
 * The course-level facts a readiness evaluation needs, flattened to scalars.
 *
 * This exists so the evaluator can live in Authoring without importing Catalog's Course model —
 * Authoring is not permitted to depend on Catalog, and the existing references are grandfathered
 * rather than allowed. Catalog owns Course and does the mapping; Authoring receives only what it
 * needs to answer the question.
 *
 * A side benefit: the rules become trivially testable without touching a database.
 *
 * TODO: Commerce pricing facts (price, currency, free/paid, entitlement plan) are NOT carried here.
 * Reading them would require Catalog or Authoring to depend on Commerce, which the boundary
 * forbids, and there is no pricing port today. A "course is published but has no price" rule needs
 * that port first; inventing a price field on this DTO and populating it from anywhere else would
 * be the boundary violation wearing a different hat. Tracked as: Commerce Pricing Read Port.
 */
final readonly class CourseReadinessInput
{
    /**
     * @param  string|null  $visibility  the raw Visibility backing value; null when unset. Passed as
     *                                   a string rather than the enum so the DTO stays free of any
     *                                   assumption about which enum the owning domain uses.
     */
    public function __construct(
        public int $courseId,
        public string $coursePublicId,
        public ?string $description,
        public ?string $thumbnailPath,
        public bool $hasInstructor,
        public ?string $visibility = null,
    ) {}
}
