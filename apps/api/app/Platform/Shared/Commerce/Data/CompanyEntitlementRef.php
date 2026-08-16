<?php

namespace App\Platform\Shared\Commerce\Data;

/**
 * Everything the manager portal is allowed to know about one purchased training entitlement: what was
 * bought, which courses it opens, how many seats are left, when it lapses, and what the policy
 * permits. Scalars and plain arrays only — no Commerce model crosses the seam.
 *
 * `seatsPurchased` and `seatsAvailable` are null for an unlimited (whole-organization) licence,
 * which the portal renders as such instead of inventing a number.
 */
final readonly class CompanyEntitlementRef
{
    /**
     * @param  list<array{id: string, title: string}>  $courses
     */
    public function __construct(
        public string $publicId,
        public string $productTitle,
        public string $orderPublicId,
        public array $courses,
        public ?int $seatsPurchased,
        public int $seatsUsed,
        public ?int $seatsAvailable,
        public string $status,
        public ?string $accessStartsAt,
        public ?string $accessEndsAt,
        public string $seatMode,
        public string $reassignmentPolicy,
        public ?int $reassignmentProgressThreshold,
        public ?string $certificateBranding,
        public bool $employeeAccessExpiresWithPurchase,
        public bool $assignable,
    ) {}
}
