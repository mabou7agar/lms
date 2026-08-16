<?php

namespace App\Platform\Shared\Commerce\Data;

/**
 * An employee the manager wants to give a seat to. CRM resolves who these are (a member, everyone in
 * a department, everyone on a team) because membership is its domain; Commerce only needs the two
 * ids — the membership row that holds the seat and the platform user who receives the courses.
 */
final readonly class SeatCandidate
{
    public function __construct(
        public int $organizationMemberId,
        public int $userId,
    ) {}
}
