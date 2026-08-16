<?php

namespace App\Platform\Shared\Commerce\Data;

/**
 * One employee currently holding (or previously holding) a seat in a purchase. Identified by their
 * membership id so the manager portal can join it back to the member row it already renders.
 */
final readonly class CompanySeatHolderRef
{
    public function __construct(
        public string $publicId,
        public int $organizationMemberId,
        public int $userId,
        public ?string $assignedAt,
        public ?string $revokedAt,
        public bool $active,
    ) {}
}
