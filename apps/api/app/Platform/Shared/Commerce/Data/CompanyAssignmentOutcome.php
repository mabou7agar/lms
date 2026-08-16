<?php

namespace App\Platform\Shared\Commerce\Data;

/**
 * What a seat assignment actually did. `alreadyAssigned` is separated from `assigned` so a repeated
 * assignment reads as the no-op it is rather than as new consumption, which is what makes the
 * portal's seat maths believable.
 */
final readonly class CompanyAssignmentOutcome
{
    public function __construct(
        public int $assigned,
        public int $alreadyAssigned,
        public int $seatsUsed,
        public ?int $seatsAvailable,
        public int $coursesGranted,
    ) {}
}
