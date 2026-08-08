<?php

declare(strict_types=1);

namespace App\Domains\Qna\Support;

/**
 * Boundary-safe projection of the two course columns the Q&A context is allowed to know about: the
 * internal id (its opaque authorization/tenancy anchor) and the owning organization id (the value
 * stamped transitively onto a question). Produced only by CourseLocator, which reads the `courses`
 * table by name — never by importing Catalog's Course model.
 */
final readonly class ResolvedCourse
{
    public function __construct(
        public int $id,
        public ?int $organizationId,
    ) {}
}
