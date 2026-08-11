<?php

namespace App\Platform\Shared\Enterprise\Data;

/**
 * Read-only snapshot of an organization's active subscription + seat usage, crossing the Commerce→CRM
 * boundary so the enterprise portal can render seat utilisation without importing a Commerce model.
 * Owned by Shared, produced by Commerce's org-subscription exposure adapter, consumed by CRM.
 */
final readonly class OrganizationSeatSummary
{
    public function __construct(
        public string $subscriptionPublicId,
        public string $status,
        public int $purchased,
        public int $used,
        public int $available,
    ) {}

    /**
     * @return array{
     *     subscription_id: string,
     *     status: string,
     *     seats: array{purchased: int, used: int, available: int}
     * }
     */
    public function toArray(): array
    {
        return [
            'subscription_id' => $this->subscriptionPublicId,
            'status' => $this->status,
            'seats' => [
                'purchased' => $this->purchased,
                'used' => $this->used,
                'available' => $this->available,
            ],
        ];
    }
}
