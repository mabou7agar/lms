<?php

namespace App\Contexts\Commerce\Services;

use App\Contexts\Commerce\Enums\CompanyEntitlementStatus;
use App\Contexts\Commerce\Enums\SeatMode;
use App\Contexts\Commerce\Enums\SeatReassignmentPolicy;
use App\Contexts\Commerce\Models\CompanyEntitlement;
use App\Contexts\Commerce\Models\CompanyEntitlementAssignment;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\Product;
use App\Platform\Shared\Analytics\AnalyticsEventName;
use App\Platform\Shared\Analytics\Contracts\AnalyticsEventRecorder;
use App\Platform\Shared\Analytics\Data\AnalyticsEventInput;
use App\Platform\Shared\Commerce\Data\CompanyAssignmentOutcome;
use App\Platform\Shared\Commerce\Data\SeatCandidate;
use App\Platform\Shared\Commerce\Exceptions\CompanyEntitlementNotAssignableException;
use App\Platform\Shared\Commerce\Exceptions\CompanySeatsExhaustedException;
use App\Platform\Shared\Commerce\Exceptions\SeatReassignmentBlockedException;
use App\Platform\Shared\Learning\Contracts\CompanySeatEnrollmentPort;
use App\Platform\Shared\Services\BaseService;
use Illuminate\Support\Carbon;

/**
 * Seat mechanics for company purchases: turning a paid company order into assignable entitlements,
 * and handing those seats out.
 *
 * Seat counting is done the same way the CRM subscription pool does it — the entitlement row is
 * locked for the whole assign/revoke, so two managers clicking at once cannot both take the last
 * seat. `seats_used` is a stored counter rather than a live count for the same reason it is on
 * seat_pools: the portal reads it constantly and the assignment table is append-only history.
 *
 * Course access is granted through Learning's own port, never by writing enrollments here.
 */
class CompanyEntitlementService extends BaseService
{
    public function __construct(
        private readonly CompanySeatEnrollmentPort $enrollments,
        private readonly AnalyticsEventRecorder $analytics,
        private readonly SeatPurchaseService $seats,
    ) {}

    /**
     * Create the entitlements a paid company order earns — one per purchased product.
     *
     * Idempotent through the unique (order_id, product_id) index, so a webhook delivered twice
     * cannot double a company's seats. Returns the entitlements that were newly created.
     *
     * @return list<CompanyEntitlement>
     */
    public function provisionForOrder(Order $order): array
    {
        $organizationId = $order->organization_id;

        if ($organizationId === null) {
            return [];
        }

        $purchasedAt = $order->paid_at ?? $order->placed_at ?? Carbon::now();
        $created = [];

        $order->loadMissing('items.product');

        foreach ($order->items as $item) {
            $product = $item->getRelation('product');

            if (! $product instanceof Product) {
                continue;
            }

            $entitlement = CompanyEntitlement::firstOrCreate(
                ['order_id' => $order->id, 'product_id' => $product->id],
                $this->attributesFor($product, (int) $organizationId, $purchasedAt, $item->quantityOrOne()),
            );

            if ($entitlement->wasRecentlyCreated) {
                $created[] = $entitlement;
            }
        }

        return $created;
    }

    /**
     * The entitlement row for a product bought on this order, snapshotting the policy as sold.
     *
     * @return array<string, mixed>
     */
    private function attributesFor(Product $product, int $organizationId, Carbon $purchasedAt, int $quantity): array
    {
        return [
            'organization_id' => $organizationId,
            'seats_purchased' => $this->seats->entitlementSeats($product, $quantity),
            'seats_used' => 0,
            'access_starts_at' => $purchasedAt,
            'access_ends_at' => $product->accessEndsAfter($purchasedAt),
            'status' => CompanyEntitlementStatus::Active->value,
            'seat_mode' => ($product->seat_mode ?? SeatMode::Fixed)->value,
            'seat_reassignment_policy' => ($product->seat_reassignment_policy ?? SeatReassignmentPolicy::Always)->value,
            'reassignment_progress_threshold' => $product->reassignment_progress_threshold,
            'company_certificate_branding' => $product->company_certificate_branding?->value,
            'employee_access_expires_with_purchase' => $product->employee_access_expires_with_purchase,
            // Certificates are snapshotted for the same reason seats are: a bundle sold with a
            // credential must keep offering one even if the product is edited afterwards.
            'certificate_enabled' => $product->certificate_enabled,
            'certificate_expiry_type' => $product->certificate_expiry_type?->value,
            'certificate_expiry_value' => $product->certificate_expiry_value,
            'certificate_expires_at' => $product->certificate_expires_at,
        ];
    }

    /**
     * Give each candidate a seat, then grant them every course the purchase includes.
     *
     * The whole operation runs under a row lock on the entitlement: capacity is checked and consumed
     * inside it, so the pool cannot be over-drawn by concurrent managers. Candidates who already hold
     * a seat cost nothing — they are re-granted (which refreshes their access window) and counted as
     * already assigned, which is what makes repeating an assignment safe.
     *
     * @param  list<SeatCandidate>  $candidates
     */
    public function assign(CompanyEntitlement $entitlement, array $candidates): CompanyAssignmentOutcome
    {
        if (! $entitlement->isAssignable()) {
            throw new CompanyEntitlementNotAssignableException;
        }

        $courseIds = $this->courseIds($entitlement);

        /** @var array{fresh: list<SeatCandidate>, existing: list<SeatCandidate>} $partition */
        $partition = $this->transaction(function () use ($entitlement, $candidates): array {
            $locked = CompanyEntitlement::whereKey($entitlement->id)->lockForUpdate()->first();

            if (! $locked instanceof CompanyEntitlement || ! $locked->isAssignable()) {
                throw new CompanyEntitlementNotAssignableException;
            }

            $fresh = [];
            $existing = [];

            foreach ($this->dedupe($candidates) as $candidate) {
                $held = CompanyEntitlementAssignment::where('company_entitlement_id', $locked->id)
                    ->where('organization_member_id', $candidate->organizationMemberId)
                    ->whereNull('revoked_at')
                    ->exists();

                if ($held) {
                    $existing[] = $candidate;

                    continue;
                }

                $fresh[] = $candidate;
            }

            $available = $locked->seatsAvailable();

            if ($available !== null && count($fresh) > $available) {
                throw new CompanySeatsExhaustedException(
                    'This purchase has '.$available.' seat(s) left, but '.count($fresh).' were requested.',
                    ['available' => $available, 'requested' => count($fresh)],
                );
            }

            foreach ($fresh as $candidate) {
                CompanyEntitlementAssignment::create([
                    'company_entitlement_id' => $locked->id,
                    'organization_member_id' => $candidate->organizationMemberId,
                    'user_id' => $candidate->userId,
                    'assigned_at' => now(),
                ]);
            }

            if ($fresh !== [] && ! $locked->isUnlimited()) {
                $locked->increment('seats_used', count($fresh));
            }

            return ['fresh' => $fresh, 'existing' => $existing];
        });

        // Enrollments are granted OUTSIDE the seat transaction: they call across a context seam and
        // are idempotent, so holding a row lock over them would only widen the window for contention.
        $accessEndsAt = $entitlement->employeeAccessEndsAt()?->toIso8601String();
        $granted = 0;

        foreach ([...$partition['fresh'], ...$partition['existing']] as $candidate) {
            foreach ($courseIds as $courseId) {
                $this->enrollments->grantCompanySeat($courseId, $candidate->userId, $accessEndsAt);
                $granted++;
            }
        }

        // One event per NEWLY seated employee. A re-assignment consumed no seat and is not a new
        // fact, so it is not one — which is also why the key is per (entitlement, member).
        $this->analytics->recordMany(array_map(
            fn (SeatCandidate $c): AnalyticsEventInput => new AnalyticsEventInput(
                name: AnalyticsEventName::CompanySeatAssigned->value,
                userId: $c->userId,
                organizationId: (int) $entitlement->organization_id,
                productId: (int) $entitlement->product_id,
                metadata: ['courses' => count($courseIds)],
                dedupKey: 'seat_assigned:'.$entitlement->public_id.':'.$c->organizationMemberId,
            ),
            $partition['fresh'],
        ));

        return $this->outcome(
            $entitlement->refresh(),
            assigned: count($partition['fresh']),
            alreadyAssigned: count($partition['existing']),
            coursesGranted: $granted,
        );
    }

    /**
     * Take a member's seat back, subject to the product's reassignment policy, and withdraw the
     * course access it granted. A member who holds no seat is a no-op rather than an error, so a
     * double-click cannot produce a spurious failure.
     */
    public function revoke(CompanyEntitlement $entitlement, int $organizationMemberId): CompanyAssignmentOutcome
    {
        $assignment = CompanyEntitlementAssignment::where('company_entitlement_id', $entitlement->id)
            ->where('organization_member_id', $organizationMemberId)
            ->whereNull('revoked_at')
            ->first();

        if ($assignment === null) {
            return $this->outcome($entitlement, 0, 0, 0);
        }

        $courseIds = $this->courseIds($entitlement);

        $this->assertReassignmentAllowed($entitlement, $courseIds, (int) $assignment->user_id);

        $this->transaction(function () use ($entitlement, $assignment): void {
            $locked = CompanyEntitlement::whereKey($entitlement->id)->lockForUpdate()->first();

            $held = CompanyEntitlementAssignment::whereKey($assignment->id)
                ->whereNull('revoked_at')
                ->first();

            if (! $held instanceof CompanyEntitlementAssignment || ! $locked instanceof CompanyEntitlement) {
                return; // someone else revoked it first
            }

            $held->forceFill(['revoked_at' => now()])->save();

            if (! $locked->isUnlimited() && $locked->seats_used > 0) {
                $locked->decrement('seats_used');
            }
        });

        foreach ($courseIds as $courseId) {
            $this->enrollments->revokeCompanySeat($courseId, (int) $assignment->user_id);
        }

        // Keyed on the assignment row rather than the member: the same person can hold, lose and
        // regain a seat, and each of those is a separate thing that happened.
        $this->analytics->record(new AnalyticsEventInput(
            name: AnalyticsEventName::CompanySeatRevoked->value,
            userId: (int) $assignment->user_id,
            organizationId: (int) $entitlement->organization_id,
            productId: (int) $entitlement->product_id,
            dedupKey: 'seat_revoked:'.$assignment->public_id,
        ));

        return $this->outcome($entitlement->refresh(), 0, 0, 0);
    }

    /**
     * May this seat be taken back? Recalling a licence erases the holder's claim to it, so the
     * product's policy decides:
     *
     *   - `always` — no restriction;
     *   - `never` — the seat stays with its first holder, full stop;
     *   - `before_start` — only while the employee has not touched any of the courses;
     *   - `before_progress_threshold` — only while they are below the admin's percentage. A product
     *     configured for a threshold without one set falls back to the stricter before-start rule
     *     rather than guessing a number in the company's favour.
     *
     * Progress is the HIGHEST reached across the bundle: an employee who finished one course of five
     * has plainly used the licence.
     *
     * @param  list<int>  $courseIds
     */
    private function assertReassignmentAllowed(CompanyEntitlement $entitlement, array $courseIds, int $userId): void
    {
        $policy = $entitlement->seat_reassignment_policy;

        if ($policy === SeatReassignmentPolicy::Always) {
            return;
        }

        if ($policy === SeatReassignmentPolicy::Never) {
            throw new SeatReassignmentBlockedException(
                'This purchase does not allow seats to be reassigned once given out.',
                ['policy' => $policy->value],
            );
        }

        $progress = $this->enrollments->highestProgressPercentage($courseIds, $userId);

        $limit = $policy === SeatReassignmentPolicy::BeforeStart
            ? 1
            : max(1, (int) ($entitlement->reassignment_progress_threshold ?? 1));

        if ($progress >= $limit) {
            throw new SeatReassignmentBlockedException(
                $policy === SeatReassignmentPolicy::BeforeStart
                    ? 'This seat can only be reassigned before the employee starts the course.'
                    : 'This seat can only be reassigned below '.$limit.'% progress; the employee has reached '.$progress.'%.',
                ['policy' => $policy->value, 'progress' => $progress, 'threshold' => $limit],
            );
        }
    }

    /**
     * The courses a purchase opens.
     *
     * @return list<int>
     */
    public function courseIds(CompanyEntitlement $entitlement): array
    {
        $product = $entitlement->relationLoaded('product')
            ? $entitlement->getRelation('product')
            : $entitlement->product()->with('courses')->first();

        return $product instanceof Product ? $product->courseIds() : [];
    }

    /**
     * Drop duplicate candidates so the same employee named twice (a member who is in both a targeted
     * department and a targeted team) never consumes two seats.
     *
     * @param  list<SeatCandidate>  $candidates
     * @return list<SeatCandidate>
     */
    private function dedupe(array $candidates): array
    {
        $unique = [];

        foreach ($candidates as $candidate) {
            $unique[$candidate->organizationMemberId] = $candidate;
        }

        return array_values($unique);
    }

    private function outcome(CompanyEntitlement $entitlement, int $assigned, int $alreadyAssigned, int $coursesGranted): CompanyAssignmentOutcome
    {
        return new CompanyAssignmentOutcome(
            assigned: $assigned,
            alreadyAssigned: $alreadyAssigned,
            seatsUsed: (int) $entitlement->seats_used,
            seatsAvailable: $entitlement->seatsAvailable(),
            coursesGranted: $coursesGranted,
        );
    }
}
