<?php

namespace App\Platform\Shared\Enterprise\Contracts;

use App\Platform\Shared\Enterprise\Data\OrganizationRef;

/**
 * Resolves which organization a user buys on behalf of, and its billing identity.
 *
 * Commerce needs both to own a company purchase, but organizations live in CRM. Only scalars and a
 * DTO cross this port, so neither context learns the other's models.
 */
interface OrganizationLookupPort
{
    /**
     * The organization this user may purchase for — one they hold an ACTIVE owner/admin membership
     * in — or null when they manage none.
     */
    public function managedOrganizationIdFor(int $userId): ?int;

    /** Billing identity for an organization, or null when it does not exist. */
    public function organizationRef(int $organizationId): ?OrganizationRef;

    /**
     * Platform user ids of the people who run this organization — its ACTIVE owners and admins, the
     * same authority that may commit it to a purchase. These are who gets told when the company's
     * training is about to lapse; a plain member cannot act on that news.
     *
     * Members without a linked account are omitted: an invitation that was never accepted has
     * nobody to notify.
     *
     * @return list<int>
     */
    public function managerUserIds(int $organizationId): array;
}
