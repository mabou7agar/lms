<?php

namespace App\Platform\Shared\Commerce\Contracts;

/**
 * Cross-context entitlement boundary. This port lives in Shared so the Learning context can ask
 * "may this user access this course?" WITHOUT importing anything from Commerce — the whole point of
 * the boundary. Commerce owns the only implementation (EntitlementAdapter); consumers depend on
 * this contract, never on the concrete class.
 *
 * An entitlement is granted by EITHER a paid one-off purchase (an OrderCourseGrant on a paid order)
 * OR an active subscription whose plan's product bundles the course. Identifiers are internal
 * integer ids on both sides; no domain models cross the boundary — only scalars.
 */
interface EntitlementPort
{
    /**
     * Whether the given user is currently entitled to the given course, from any source.
     */
    public function hasCourseEntitlement(int $userId, int $courseId): bool;

    /**
     * Every course id the user is currently entitled to, de-duplicated across all sources.
     *
     * @return list<int>
     */
    public function entitledCourseIds(int $userId): array;
}
