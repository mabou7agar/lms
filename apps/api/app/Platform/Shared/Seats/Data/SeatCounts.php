<?php

namespace App\Platform\Shared\Seats\Data;

/**
 * Immutable seat-usage snapshot for one seat pool — the ONLY seat shape that crosses the
 * Commerce↔CRM boundary. No Eloquent model, no assignment rows: just the three scalars a
 * subscription surface needs to render "X purchased, Y used, Z free".
 *
 *   - purchased: the pool's capacity (mirrors the organization subscription's seat quantity);
 *   - assigned:  seats currently held by active (non-revoked) members;
 *   - available: free capacity, never negative.
 *
 * Owned by Shared, produced by the CRM seat adapter, consumed by Commerce. Anything richer than
 * these counts is a CRM-domain concern and must not be smuggled through this DTO.
 */
final readonly class SeatCounts
{
    public function __construct(
        public int $purchased,
        public int $assigned,
        public int $available,
    ) {}
}
